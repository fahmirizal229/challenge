<?php

namespace App\Jobs;

use App\Models\ProcessExecution;
use App\Models\ProcessStepExecution;
use App\Services\CloudStackClient;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job khusus yang bertugas sebagai "Tim Pembatalan / Kebersihan" (Rollback).
 * Mengambil perintah rollback dari database dan mengekstrak resource ID secara otomatis dari response step sebelumnya.
 */
class ExecuteRollbackJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 180;

    protected string $execution_id;

    public function __construct(string $execution_id)
    {
        $this->execution_id = $execution_id;
    }

    /**
     * Titik awal eksekusi proses rollback.
     */
    public function handle(CloudStackClient $client): void
    {
        // 1. Ambil data proses eksekusi dari database
        $execution = ProcessExecution::find($this->execution_id);
        if (!$execution)
        {
            return;
        }

        // 2. Ambil semua langkah yang memiliki perintah pembatalan ('rollback_command')
        // dan statusnya sudah 'success' (artinya resource-nya benar-benar sempat jadi di server).
        // Kita urutkan secara terbalik (order DESC) agar resource yang dibuat belakangan dihapus lebih dulu.
        $steps_to_rollback = $execution->stepExecutions()
            ->whereNotNull('rollback_command')
            ->where('status', 'success')
            ->reorder('order', 'desc')
            ->get();

        // 3. Lakukan penghapusan untuk setiap resource satu per satu
        foreach ($steps_to_rollback as $step)
        {
            // Ambil ID resource yang pernah dibuat oleh langkah ini dari respon CloudStack
            $resource_id = $this->extractResourceId($step);

            if (!empty($resource_id))
            {
                $this->rollbackResource(
                    $execution,
                    $client,
                    "rollback_{$step->step_name}",
                    $step->rollback_command,       // Perintah delete dari database (contoh: 'deleteNetwork', 'deleteVpc')
                    ['id' => $resource_id]         // ID barang yang mau dihapus
                );
            }
        }

        // 4. Setelah semua pembersihan selesai, tandai status proses utama menjadi 'rolled_back'
        $execution->update(['status' => 'rolled_back']);
    }

    /**
     * Fungsi pintar untuk mencari dan mengambil ID resource dari rekaman respon CloudStack secara otomatis.
     */
    protected function extractResourceId(ProcessStepExecution $step): ?string
    {
        $payload = $step->response_payload ?? [];

        // 1. Cek jika ID berada di dalam respon async: queryasyncjobresultresponse -> jobresult -> id
        if (isset($payload['queryasyncjobresultresponse']['jobresult']))
        {
            foreach ($payload['queryasyncjobresultresponse']['jobresult'] as $val)
            {
                if (is_array($val) && isset($val['id']))
                {
                    return (string) $val['id'];
                }
            }
        }

        // 2. Cek jika ID berada di dalam respon synchronous biasa: *response -> id
        foreach ($payload as $key => $val)
        {
            if (is_array($val))
            {
                if (isset($val['id']))
                {
                    return (string) $val['id'];
                }
                foreach ($val as $sub_val)
                {
                    if (is_array($sub_val) && isset($sub_val['id']))
                    {
                        return (string) $sub_val['id'];
                    }
                }
            }
        }

        return null;
    }

    /**
     * Mengirimkan perintah delete/destroy ke API CloudStack dan menunggu sampai penghapusan tuntas.
     */
    protected function rollbackResource(
        ProcessExecution $execution,
        CloudStackClient $client,
        string $step_name,
        string $command,
        array $params
    ): void
    {
        $first_step = $execution->stepExecutions()->first();
        $master_step_id = $first_step ? $first_step->master_process_step_id : null;

        // Catat langkah rollback ini ke audit log agar tercatat di laporan riwayat
        $step = ProcessStepExecution::create([
            'process_execution_id'   => $execution->id,
            'master_process_step_id' => $master_step_id,
            'step_name'              => $step_name,
            'command'                => $command,
            'is_async'               => true,
            'status'                 => 'running',
            'order'                  => 99,
            'started_at'             => now(),
            'request_payload'        => array_merge(['command' => $command], $params),
        ]);

        try
        {
            // Kirim perintah delete ke API CloudStack
            $response = $client->request(array_merge(['command' => $command], $params));
            $response_key = strtolower($command) . 'response';
            $job_id = $response_key ? ($response[$response_key]['jobid'] ?? null) : null;

            // Jika perintah penghapusan bersifat asynchronous, tunggu sampai selesai
            if ($job_id)
            {
                $step->update([
                    'cloudstack_job_id' => $job_id,
                    'status'            => 'waiting',
                    'response_payload'  => $response,
                ]);

                // Tunggu konfirmasi dari server CloudStack (jobstatus: 1 = sukses terhapus, 2 = gagal)
                $completed = false;
                while (!$completed)
                {
                    sleep(2);
                    $query_res = $client->queryAsyncJobResult($job_id);
                    $status = (int) ($query_res['queryasyncjobresultresponse']['jobstatus'] ?? 0);

                    if ($status === 1)
                    {
                        $completed = true;
                        $step->update([
                            'status'           => 'success',
                            'response_payload' => $query_res,
                            'completed_at'     => now(),
                        ]);
                    }
                    elseif ($status === 2)
                    {
                        $completed = true;
                        $step->update([
                            'status'           => 'failed',
                            'response_payload' => $query_res,
                            'error_message'    => 'Rollback async job returned status=2',
                            'completed_at'     => now(),
                        ]);
                    }
                }
            }
            else
            {
                // Jika perintah synchronous, langsung tandai sukses terhapus
                $step->update([
                    'status'       => 'success',
                    'completed_at' => now(),
                ]);
            }
        }
        catch (Exception $e)
        {
            $step->update([
                'status'        => 'failed',
                'error_message' => "Rollback error: " . $e->getMessage(),
                'completed_at'  => now(),
            ]);
            Log::error("Rollback step {$step_name} failed: " . $e->getMessage());
        }
    }
}
