<?php

namespace App\Services;

use Exception;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Service ini bertindak seperti "Kurir Pengirim Pesan" antara aplikasi kita dengan server API CloudStack.
 * Menggunakan fitur HTTP Client bawaan Laravel (Guzzle) untuk mengirim dan menerima data.
 */
class CloudStackClient
{
    // Alamat URL API CloudStack yang dituju
    protected string $base_url;

    // Batas waktu tunggu jawaban dari server dalam detik (default 30 detik)
    protected int $default_timeout;

    /**
     * Saat service ini dibuat, kita siapkan URL API dan batas waktu tunggunya.
     */
    public function __construct(?string $base_url = null, int $default_timeout = 30)
    {
        // Ambil alamat dari file .env (CLOUDSTACK_API_URL), jika tidak ada pakai server default
        $this->base_url = $base_url ?: env('CLOUDSTACK_API_URL', 'https://fake-cs.virmata.com/api/api');
        $this->default_timeout = $default_timeout;
    }

    /**
     * Mengirimkan permintaan data (HTTP GET) ke CloudStack API.
     *
     * @param array $params Data parameter yang dikirim (seperti nama perintah, nama VM, IP, dll)
     * @param int|null $timeout Batas waktu tunggu khusus (opsional)
     * @return array Data jawaban dari CloudStack dalam bentuk array PHP
     * @throws Exception Jika terjadi error jaringan atau server CloudStack menolak
     */
    public function request(array $params, ?int $timeout = null): array
    {
        // Tentukan batas waktu tunggu
        $timeout = $timeout ?? $this->default_timeout;

        // Kirim permintaan GET menggunakan HTTP Client Laravel
        $response = Http::timeout($timeout)
            ->connectTimeout(10)
            ->acceptJson()
            ->get($this->base_url, $params);

        // Jika server memberikan kode error HTTP (misal: 500, 404, dll)
        if (!$response->successful() && $response->status() !== 431)
        {
            throw new Exception("CloudStack HTTP Error {$response->status()}: " . $response->body());
        }

        // Ubah teks format JSON dari server menjadi array data PHP
        $decoded = $response->json();
        if (!is_array($decoded))
        {
            throw new Exception("Format JSON dari CloudStack tidak valid (HTTP {$response->status()}): " . $response->body());
        }

        // Periksa apakah di dalam data jawaban terdapat pesan error dari CloudStack
        $first_key = array_key_first($decoded);
        if ($first_key && is_array($decoded[$first_key]) && isset($decoded[$first_key]['errorcode']))
        {
            $error_text = $decoded[$first_key]['errortext'] ?? 'Unknown CloudStack error';
            $error_code = $decoded[$first_key]['errorcode'];
            throw new Exception("CloudStack API Error ({$error_code}): {$error_text}");
        }

        // Kembalikan hasil data yang sukses diterima
        return $decoded;
    }

    /**
     * Fungsi khusus untuk menanyakan status tugas yang sedang berjalan di latar belakang (asynchronous).
     *
     * @param string $job_id Nomor ID tiket tugas CloudStack
     * @param int|null $timeout Batas waktu tunggu
     * @return array Hasil pengecekan status tugas
     */
    public function queryAsyncJobResult(string $job_id, ?int $timeout = null): array
    {
        return $this->request([
            'command' => 'queryAsyncJobResult',
            'jobid'   => $job_id,
        ], $timeout);
    }
}
