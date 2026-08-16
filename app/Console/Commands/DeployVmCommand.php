<?php

namespace App\Console\Commands;

use App\Models\ProcessExecution;
use App\Services\WorkflowEngine;
use Illuminate\Console\Command;

/**
 * Command terminal (Artisan CLI) untuk menjalankan proses pembuatan VM di CloudStack.
 * Cara pakai: php artisan deploy:vm [opsi]
 */
class DeployVmCommand extends Command
{
    /**
     * Format perintah terminal beserta pilihan opsinya:
     * --public-ip   : Pasang IP Publik ke VM
     * --fail-at     : Coba simulasikan error di langkah tertentu (misal: --fail-at=deploy_vm)
     * --timeout-at  : Coba simulasikan jaringan lambat/timeout (misal: --timeout-at=create_vpc)
     * --delay       : Jeda simulasi server dalam detik
     * --timeout     : Batas waktu tunggu simulasi timeout
     * --watch       : Pantau perkembangan progres secara live di layar terminal
     */
    protected $signature = 'deploy:vm
                            {--public-ip : Alokasikan public IP dan konfigurasi static NAT}
                            {--fail-at= : Nama step untuk simulasi kegagalan (misal: deploy_vm, create_vpc)}
                            {--timeout-at= : Nama step untuk simulasi timeout (misal: create_vpc, create_subnet)}
                            {--delay=2 : Delay dalam detik untuk pemrosesan mock server}
                            {--timeout=35 : Durasi timeout dalam detik untuk simulasi timeout}
                            {--watch : Pantau progres secara real-time di terminal hingga selesai}';

    protected $description = 'Menjalankan workflow deployment VM CloudStack berbasis database queue jobs';

    /**
     * Logika utama yang dijalankan saat user mengetik 'php artisan deploy:vm'.
     */
    public function handle(WorkflowEngine $engine): int
    {
        // 1. Baca semua pilihan opsi yang diketik oleh user di terminal
        $public_ip = (bool) $this->option('public-ip');
        $fail_at = $this->option('fail-at');
        $timeout_at = $this->option('timeout-at');
        $delay = (int) $this->option('delay');
        $timeout = (int) $this->option('timeout');
        $watch = (bool) $this->option('watch');

        // 2. Cetak ringkasan konfigurasi ke layar terminal
        $this->info("=================================================");
        $this->info("  CloudStack VM Deployment Workflow Initializer  ");
        $this->info("=================================================");
        $this->line("Public IP: " . ($public_ip ? '<fg=green>YES</>' : '<fg=yellow>NO</>'));
        $this->line("Fail At:   " . ($fail_at ? "<fg=red>{$fail_at}</>" : '<fg=gray>None</>'));
        $this->line("Timeout At:" . ($timeout_at ? "<fg=yellow>{$timeout_at}</>" : '<fg=gray>None</>'));
        $this->line("Delay:     {$delay}s");
        $this->line("Timeout:   {$timeout}s");
        $this->newLine();

        // 3. Minta WorkflowEngine untuk membuat sesi proses baru di database
        $execution = $engine->createExecution(
            'deploy_vm',
            $public_ip,
            $fail_at,
            $timeout_at,
            $delay,
            $timeout
        );

        // 4. Mulai jalankan langkah pertama proses tersebut
        $engine->start($execution);

        $this->info("Proses diinisiasi dengan ID: <fg=cyan>{$execution->id}</>");
        $this->line("Perintah cek status: <fg=yellow>php artisan vm:status {$execution->id}</>");
        $this->newLine();

        // 5. Jika user menambahkan opsi '--watch', tampilkan layar live monitor progres setiap detik
        if ($watch)
        {
            $this->watchExecution($execution);
        }
        else
        {
            // Jika tidak, cukup tampilkan tabel status awal satu kali
            $this->displayStatusTable($execution);
        }

        return Command::SUCCESS;
    }

    /**
     * Memantau perkembangan pembuatan VM secara langsung (live monitor) setiap detik di terminal.
     */
    protected function watchExecution(ProcessExecution $execution): void
    {
        $this->info("Memantau progres proses... (Tekan Ctrl+C untuk berhenti)");
        $this->newLine();

        while (true)
        {
            // Ambil data terbaru dari database
            $execution->refresh();
            $steps = $execution->stepExecutions()->get();

            // Tentukan warna status: hijau (sukses), merah (gagal/dibatalkan), kuning (proses rollback), biru (sedang jalan)
            $status_color = match ($execution->status)
            {
                'success' => 'green',
                'failed', 'rolled_back' => 'red',
                'rolling_back' => 'yellow',
                default => 'cyan',
            };

            // Bersihkan layar terminal agar tampilan live update terlihat rapi
            $this->output->write("\033[2J\033[;H");
            $this->info("=================================================");
            $this->info("  Process ID: {$execution->id} | Status: <fg={$status_color}>" . strtoupper($execution->status) . "</>");
            $this->info("=================================================");

            $headers = ['Step Name', 'Command', 'Async', 'Status', 'Attempts', 'Job ID', 'Error / Detail'];
            $rows = [];

            // Tampilkan baris untuk setiap langkah kerja
            foreach ($steps as $step)
            {
                $step_color = match ($step->status)
                {
                    'success' => 'green',
                    'failed' => 'red',
                    'running', 'waiting' => 'cyan',
                    'timeout', 'retrying' => 'yellow',
                    'skipped' => 'gray',
                    default => 'white',
                };

                $rows[] = [
                    $step->step_name,
                    $step->command,
                    $step->is_async ? 'YES' : 'NO',
                    "<fg={$step_color}>" . strtoupper($step->status) . "</>",
                    $step->attempt > 0 ? "{$step->attempt}/5" : '-',
                    $step->cloudstack_job_id ? substr($step->cloudstack_job_id, 0, 8) . '...' : '-',
                    $step->error_message ? substr($step->error_message, 0, 35) . '...' : '-',
                ];
            }

            $this->table($headers, $rows);

            // Tampilkan daftar resource yang sudah berhasil dibuat dari response_payload
            $vpc_step = $steps->where('step_name', 'create_vpc')->first();
            $subnet_step = $steps->where('step_name', 'create_subnet')->first();
            $acl_list_step = $steps->where('step_name', 'create_acl_list')->first();
            $acl_rule_step = $steps->where('step_name', 'create_acl_rule')->first();
            $vm_step = $steps->where('step_name', 'deploy_vm')->first();
            $nat_step = $steps->where('step_name', 'enable_static_nat')->first();

            $this->info("Provisioned Steps Status:");
            foreach ($steps as $step)
            {
                if ($step->status === 'success')
                {
                    $this->line("  - <fg=gray>{$step->step_name}:</> <fg=green>SUCCESS</>");
                }
            }

            // Jika alur kerja sudah mencapai status akhir, hentikan perulangan live monitor
            if (in_array($execution->status, ['success', 'failed', 'rolled_back']))
            {
                $this->newLine();
                $this->info("Workflow selesai dengan status: <fg={$status_color}>" . strtoupper($execution->status) . "</>");
                break;
            }

            // Tunggu 1 detik sebelum mengecek status ke database lagi
            sleep(1);
        }
    }

    /**
     * Menampilkan tabel status langkah awal secara ringkas.
     */
    protected function displayStatusTable(ProcessExecution $execution): void
    {
        $steps = $execution->stepExecutions()->get();
        $headers = ['Order', 'Step Name', 'Command', 'Async', 'Status'];
        $rows = [];

        foreach ($steps as $step)
        {
            $rows[] = [
                $step->order,
                $step->step_name,
                $step->command,
                $step->is_async ? 'YES' : 'NO',
                $step->status,
            ];
        }

        $this->table($headers, $rows);
    }
}
