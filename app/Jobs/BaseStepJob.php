<?php

namespace App\Jobs;

use App\Models\ProcessExecution;
use App\Models\ProcessStepExecution;
use App\Services\CloudStackClient;
use App\Services\WorkflowEngine;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Class dasar (pondasi) untuk setiap langkah pekerjaan (Job) provisioning CloudStack.
 * Menangani pengecekan status async (waiting), retry timeout hingga 5x, dan rollback otomatis.
 */
abstract class BaseStepJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // 0 = Tanpa batas kuota percobaan antrean Laravel (unlimited tries) agar proses yang lama tidak terputus
    public int $tries = 0;

    // Batas waktu eksekusi skrip dalam satu kali giliran (detik)
    public int $timeout = 60;

    // Menyimpan ID data langkah (ProcessStepExecution) yang sedang dikerjakan
    protected string $step_execution_id;

    /**
     * Konstruktor: Menerima ID langkah saat Job dibuat/dilempar ke antrean.
     */
    public function __construct(string $step_execution_id)
    {
        $this->step_execution_id = $step_execution_id;
    }

    /**
     * Titik awal eksekusi ketika pekerja (Queue Worker) mengambil Job ini dari antrean.
     */
    public function handle(CloudStackClient $client, WorkflowEngine $engine): void
    {
        // 1. Cari data langkah ini di database beserta data proses induknya
        $step = ProcessStepExecution::with('processExecution')->find($this->step_execution_id);
        if (!$step)
        {
            return; // Jika datanya tidak ada di database, hentikan
        }

        // 2. Cek status proses induk: jika proses induk sudah dibatalkan/sedang rollback, jangan lanjutkan
        $execution = $step->processExecution;
        if (!$execution || in_array($execution->status, ['rolling_back', 'rolled_back', 'failed']))
        {
            return;
        }

        // 3. Jika langkah ini sudah pernah dikirim ke CloudStack dan sedang menunggu hasil (punya cloudstack_job_id),
        // maka cek status hasil pengerjaannya ke CloudStack.
        if (!empty($step->cloudstack_job_id))
        {
            $this->checkJobStatus($step, $execution, $client, $engine);
            return;
        }

        // 4. Jika langkah ini baru pertama kali jalan, kirim perintah awal ke CloudStack API
        $this->executeCommand($step, $execution, $client, $engine);
    }

    /**
     * Method khusus yang wajib diisi oleh masing-masing anak Job (seperti CreateVpcJob, DeployVmJob)
     * untuk menyusun data apa saja yang mau dikirim ke CloudStack (misal: nama VPC, IP, RAM, dsb).
     */
    abstract protected function buildPayload(ProcessStepExecution $step, ProcessExecution $execution, CloudStackClient $client): array;

    /**
     * Mengecek status pekerjaan di CloudStack yang berjalan di latar belakang (asynchronous).
     */
    protected function checkJobStatus(
        ProcessStepExecution $step,
        ProcessExecution $execution,
        CloudStackClient $client,
        WorkflowEngine $engine
    ): void
    {
        try
        {
            // Tanyakan ke CloudStack: "Apakah tugas dengan ID ini sudah selesai?"
            $query_res = $client->queryAsyncJobResult($step->cloudstack_job_id);
            $async_res = $query_res['queryasyncjobresultresponse'] ?? [];
            $job_status = (int) ($async_res['jobstatus'] ?? 0);

            // Jika job_status = 0: Artinya server CloudStack masih memproses.
            // Kita ubah status jadi 'waiting', lalu kembalikan Job ke antrean untuk dicek lagi 5 detik kemudian.
            if ($job_status === 0)
            {
                $step->update(['status' => 'waiting']);
                $this->release(5); // Jeda 5 detik sebelum mengecek kembali
                return;
            }

            // Jika job_status = 1: Artinya CloudStack berhasil menyelesaikan tugas ini!
            if ($job_status === 1)
            {
                $step->update(['response_payload' => $query_res]);
                $engine->handleStepSuccess($step); // Beritahu engine bahwa langkah ini sukses
                return;
            }

            // Jika job_status = 2: Artinya terjadi error/kegagalan di server CloudStack
            if ($job_status === 2)
            {
                $err_text = $async_res['jobresult']['errortext'] ?? 'Async CloudStack job returned status = 2';
                $engine->handleStepFailure($step, $err_text); // Beritahu engine bahwa langkah ini gagal (picu rollback)
                return;
            }
        }
        catch (ConnectionException $e)
        {
            // Jika koneksi internet/jaringan ke CloudStack putus saat mengecek status
            $this->handleTimeout($step, $e->getMessage(), $engine);
        }
        catch (Exception $e)
        {
            if ($this->isTimeoutException($e))
            {
                $this->handleTimeout($step, $e->getMessage(), $engine);
            }
            else
            {
                $engine->handleStepFailure($step, "Error saat cek status: " . $e->getMessage());
            }
        }
    }

    /**
     * Mengirimkan perintah pembuatan resource ke CloudStack API.
     */
    protected function executeCommand(
        ProcessStepExecution $step,
        ProcessExecution $execution,
        CloudStackClient $client,
        WorkflowEngine $engine
    ): void
    {
        try
        {
            // Susun parameter data: gabungan dari nama perintah dan data khusus dari anak Job
            $params = array_merge([
                'command' => $step->command,
                'delay'   => $execution->delay_seconds,
            ], $this->buildPayload($step, $execution, $client));

            // Jika user memilih opsi simulasi gagal pada langkah ini
            if ($execution->fail_at === $step->step_name)
            {
                $params['result'] = '2';
            }

            // Jika user memilih opsi simulasi timeout pada langkah ini
            if ($execution->timeout_at === $step->step_name)
            {
                $params['timeout'] = $execution->timeout_seconds;
            }

            // Catat data yang dikirim dan ubah status menjadi 'running'
            $step->update([
                'request_payload' => $params,
                'status'          => 'running',
            ]);

            // Hitung durasi batas tunggu (timeout)
            $client_timeout = isset($params['timeout']) ? min(30, max(2, (int)$params['timeout'] - 5)) : 30;

            // Kirim request ke server CloudStack
            $response = $client->request($params, $client_timeout);
            $step->update(['response_payload' => $response]);

            // Jika perintah ini bertipe asynchronous (butuh waktu proses di server):
            if ($step->is_async)
            {
                $response_key = strtolower($step->command) . 'response';
                $job_id = $response_key ? ($response[$response_key]['jobid'] ?? null) : null;

                if (!$job_id)
                {
                    throw new Exception("Perintah async '{$step->command}' tidak mengembalikan jobid: " . json_encode($response));
                }

                // Simpan ID tiket pekerjaan (job_id) dan ubah status jadi 'waiting'
                $step->update([
                    'cloudstack_job_id' => $job_id,
                    'status'            => 'waiting',
                ]);

                // Lepaskan tugas kembali ke antrean untuk dicek 5 detik kemudian
                $this->release(5);
            }
            else
            {
                // Jika perintah ini langsung selesai seketika (synchronous):
                $engine->handleStepSuccess($step); // Langsung tandai sukses
            }
        }
        catch (ConnectionException $e)
        {
            // Tangani jika terjadi timeout saat pengiriman request awal
            $this->handleTimeout($step, $e->getMessage(), $engine);
        }
        catch (Exception $e)
        {
            if ($this->isTimeoutException($e))
            {
                $this->handleTimeout($step, $e->getMessage(), $engine);
            }
            else
            {
                $engine->handleStepFailure($step, $e->getMessage());
            }
        }
    }

    /**
     * Memeriksa apakah suatu error merupakan masalah batas waktu koneksi (timeout).
     */
    protected function isTimeoutException(Exception $e): bool
    {
        $msg = strtolower($e->getMessage());
        return str_contains($msg, 'timed out') || str_contains($msg, 'curl error 28');
    }

    /**
     * Mengatur kebijakan percobaan ulang otomatis jika terjadi timeout (maksimal 5 kali percobaan).
     */
    protected function handleTimeout(ProcessStepExecution $step, string $error_message, WorkflowEngine $engine): void
    {
        $new_attempt = $step->attempt + 1; // Tambah hitungan percobaan (+1)

        // Catat ke database bahwa langkah ini sedang mencoba ulang (retrying)
        $step->update([
            'attempt'       => $new_attempt,
            'status'        => 'retrying',
            'error_message' => "Percobaan {$new_attempt}/5 timeout: {$error_message}",
        ]);

        // Jika belum mencapai 5 kali percobaan, coba lagi 5 detik kemudian
        if ($new_attempt < 5)
        {
            $this->release(5);
        }
        else
        {
            // Jika sudah 5 kali mencoba dan tetap gagal karena timeout, nyatakan langkah gagal dan picu rollback
            $engine->handleStepFailure($step, "Gagal setelah 5x percobaan timeout.");
        }
    }
}
