<?php

namespace App\Services;

use App\Jobs\ExecuteRollbackJob;
use App\Models\MasterProcess;
use App\Models\ProcessExecution;
use App\Models\ProcessStepExecution;
use Illuminate\Support\Str;

/**
 * Service ini adalah "Mandor / Pengatur Alur Utama" (Orchestrator).
 * Tugasnya mengawasi seluruh jalannya proses pembuatan VM:
 * 1. Menggandakan resep/langkah kerja dari master data ke riwayat eksekusi.
 * 2. Mengecek siapa saja langkah yang siap jalan secara bersamaan (paralel).
 * 3. Menyatakan proses sukses jika semua langkah selesai.
 * 4. Memerintahkan pembatalan (rollback) jika ada salah satu langkah yang gagal.
 */
class WorkflowEngine
{
    /**
     * Membuat sesi eksekusi baru dan menyalin seluruh langkah dari template master data.
     */
    public function createExecution(
        string $process_code = 'deploy_vm',
        bool $public_ip = false,
        ?string $fail_at = null,
        ?string $timeout_at = null,
        int $delay_seconds = 2,
        int $timeout_seconds = 35
    ): ProcessExecution {
        // 1. Ambil template master proses dari database
        $master_process = MasterProcess::where('code', $process_code)->firstOrFail();

        // 2. Buat catatan proses baru dengan status awal 'pending'
        $execution = ProcessExecution::create([
            'master_process_id' => $master_process->id,
            'status'            => 'pending',
            'public_ip'         => $public_ip,
            'fail_at'           => $fail_at,
            'timeout_at'        => $timeout_at,
            'delay_seconds'     => $delay_seconds,
            'timeout_seconds'   => $timeout_seconds,
            'resources'         => [
                'vpc_id'            => null,
                'subnet_id'         => null,
                'acl_list_id'       => null,
                'acl_rule_id'       => null,
                'vm_id'             => null,
                'public_ip_id'      => null,
                'public_ip_address' => null,
            ],
        ]);

        // 3. Salin setiap template langkah ke tabel 'process_step_executions' untuk sesi ini
        foreach ($master_process->steps as $master_step)
        {
            ProcessStepExecution::create([
                'process_execution_id'   => $execution->id,
                'master_process_step_id' => $master_step->id,
                'step_name'              => $master_step->step_name,
                'command'                => $master_step->command,
                'is_async'               => $master_step->is_async,
                'rollback_command'       => $master_step->rollback_command,
                'order'                  => $master_step->order,
                'depends_on'             => $master_step->depends_on, // Syarat dependensi disalin dari master
                'status'                 => 'pending',
                'attempt'                => 0,
            ]);
        }

        return $execution;
    }

    /**
     * Memulai proses pembuatan VM dan menjalankan langkah pertama yang siap.
     */
    public function start(ProcessExecution $execution): void
    {
        // Ubah status proses utama menjadi 'running' (sedang berjalan)
        $execution->update(['status' => 'running']);

        // Jalankan langkah-langkah yang syaratnya sudah terpenuhi
        $this->dispatchEligibleSteps($execution);
    }

    /**
     * Memeriksa seluruh langkah dan melempar langkah yang sudah siap ke antrean pengerjaan (bisa paralel).
     */
    public function dispatchEligibleSteps(ProcessExecution $execution): void
    {
        // Jika proses sudah selesai, gagal, atau sedang dalam proses rollback, jangan lakukan apa-apa
        if (in_array($execution->status, ['failed', 'rolling_back', 'rolled_back', 'success']))
        {
            return;
        }

        // Ambil semua langkah eksekusi terkait proses ini dari database
        $steps = $execution->stepExecutions()->get()->keyBy('step_name');

        // Jika user tidak meminta Public IP, lewati (skip) langkah 'enable_static_nat'
        if (!$execution->public_ip && isset($steps['enable_static_nat']) && $steps['enable_static_nat']->status === 'pending')
        {
            $steps['enable_static_nat']->update(['status' => 'skipped']);
        }

        // Hitung berapa langkah yang masih menunggu (pending) dan yang sedang berjalan (active)
        $pending_steps = $steps->where('status', 'pending');
        $active_steps  = $steps->whereIn('status', ['running', 'waiting', 'retrying']);

        // Jika tidak ada lagi langkah yang pending ataupun berjalan, berarti seluruh alur SUKSES SELESAI!
        if ($pending_steps->isEmpty() && $active_steps->isEmpty())
        {
            $execution->update(['status' => 'success']);
            return;
        }

        // Periksa setiap langkah yang masih pending: apakah syaratnya di database sudah terpenuhi?
        foreach ($pending_steps as $step_name => $step)
        {
            if ($this->isStepEligible($step, $steps))
            {
                // Tandai langkah ini mulai berjalan
                $step->update([
                    'status'     => 'running',
                    'started_at' => now(),
                ]);

                // Cari dan lempar Class Job terkait secara dinamis (contoh: create_vpc -> CreateVpcJob)
                $job_class = "App\\Jobs\\" . Str::studly($step_name) . "Job";
                if (class_exists($job_class))
                {
                    $job_class::dispatch($step->id);
                }
            }
        }
    }

    /**
     * Memeriksa apakah suatu langkah sudah boleh dijalankan berdasarkan syarat di kolom 'depends_on'.
     */
    protected function isStepEligible(ProcessStepExecution $step, $steps): bool
    {
        // 1. Jika langkah ini tidak punya syarat dependensi di database, langsung boleh jalan
        if (empty($step->depends_on))
        {
            return true;
        }

        // 2. Jika ada syarat, pastikan SEMUA langkah yang disyaratkan sudah berstatus 'success'
        foreach ($step->depends_on as $required_step_name)
        {
            if (!isset($steps[$required_step_name]) || $steps[$required_step_name]->status !== 'success')
            {
                return false; // Jika ada 1 saja syarat yang belum sukses, tahan dulu langkah ini
            }
        }

        return true; // Semua syarat terpenuhi! Langkah ini boleh jalan sekarang
    }

    /**
     * Dipanggil oleh anak Job setiap kali langkahnya berhasil diselesaikan.
     */
    public function handleStepSuccess(ProcessStepExecution $step): void
    {
        // Catat status langkah menjadi 'success' dan simpan jam selesainya
        $step->update([
            'status'       => 'success',
            'completed_at' => now(),
        ]);

        // Cek apakah ada langkah berikutnya yang sekarang jadi bisa dijalankan
        $execution = $step->processExecution()->first();
        if ($execution)
        {
            $this->dispatchEligibleSteps($execution);
        }
    }

    /**
     * Dipanggil oleh anak Job jika langkahnya mengalami kegagalan fatal atau timeout 5 kali.
     */
    public function handleStepFailure(ProcessStepExecution $step, string $error_message): void
    {
        // Catat status langkah menjadi 'failed' dan catat pesan errornya
        $step->update([
            'status'        => 'failed',
            'error_message' => $error_message,
            'completed_at'  => now(),
        ]);

        // Ubah status proses utama menjadi 'rolling_back' dan panggil tim rollback untuk membersihkan
        $execution = $step->processExecution()->first();
        if ($execution && !in_array($execution->status, ['rolling_back', 'rolled_back', 'failed']))
        {
            $execution->update([
                'status'        => 'rolling_back',
                'error_message' => "Step '{$step->step_name}' gagal: {$error_message}",
            ]);

            // Dispatch job rollback untuk menghapus resource yang sudah terlanjur dibuat
            ExecuteRollbackJob::dispatch($execution->id);
        }
    }
}
