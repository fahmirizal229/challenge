<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Tabel 'process_executions': Mencatat setiap kali user meminta membuat VM baru
        Schema::create('process_executions', function (Blueprint $table)
        {
            $table->ulid('id')->primary();                                                            // ID unik proses eksekusi (contoh: '01m04hbsxxanpgk36s041pk2p3')
            $table->foreignUlid('master_process_id')->constrained('master_processes')->cascadeOnDelete(); // Mengacu pada template master_processes apa yang dipakai
            $table->string('status')->default('pending');                                             // Status utama: pending -> running -> success / failed / rolling_back / rolled_back
            $table->boolean('public_ip')->default(false);                                             // Apakah user meminta IP Publik (true) atau tidak (false)
            $table->string('fail_at')->nullable();                                                    // Digunakan untuk simulasi error pada langkah tertentu (opsional)
            $table->string('timeout_at')->nullable();                                                 // Digunakan untuk simulasi koneksi timeout pada langkah tertentu (opsional)
            $table->integer('delay_seconds')->default(2);                                             // Waktu jeda simulasi server (dalam detik)
            $table->integer('timeout_seconds')->default(35);                                          // Batas waktu tunggu simulasi timeout
            $table->json('resources')->nullable();                                                    // Menyimpan catatan ID resource yang berhasil dibuat (vpc_id, subnet_id, vm_id, dll)
            $table->text('error_message')->nullable();                                                // Catatan pesan error jika proses mengalami kegagalan
            $table->timestamps();                                                                     // Waktu dibuat & diupdate
        });

        // 2. Tabel 'process_step_executions': Mencatat jejak audit (audit log) setiap langkah pada proses eksekusi tersebut
        Schema::create('process_step_executions', function (Blueprint $table)
        {
            $table->ulid('id')->primary();                                                                    // ID unik untuk setiap langkah eksekusi
            $table->foreignUlid('process_execution_id')->constrained('process_executions')->cascadeOnDelete(); // Terhubung ke proses eksekusi induk di tabel process_executions
            $table->foreignUlid('master_process_step_id')->constrained('master_process_steps')->cascadeOnDelete(); // Terhubung ke definisi template langkahnya
            $table->string('step_name');                                                                      // Nama langkah (misal: 'create_vpc')
            $table->string('command');                                                                        // Perintah CloudStack yang dijalankan (misal: 'createVpc')
            $table->boolean('is_async')->default(false);                                                      // Menandai apakah perintah ini tipe asynchronous (butuh ditunggu hasilnya)
            $table->string('rollback_command')->nullable();                                                   // Perintah pembatalan yang dijalankan jika terjadi error
            $table->integer('order')->default(1);                                                             // Nomor urutan pengerjaan
            $table->json('depends_on')->nullable();                                                           // Syarat langkah lain yang harus 'success' sebelum langkah ini jalan
            $table->string('status')->default('pending');                                                     // Status langkah: pending, running, waiting, success, failed, retrying, skipped
            $table->string('cloudstack_job_id')->nullable();                                                  // ID tiket tugas async dari server CloudStack
            $table->integer('attempt')->default(0);                                                           // Jumlah berapa kali langkah ini dicoba ulang jika sempat timeout (maksimal 5x)
            $table->json('request_payload')->nullable();                                                      // Rekaman data yang dikirimkan ke API CloudStack
            $table->json('response_payload')->nullable();                                                     // Rekaman jawaban/hasil yang diterima dari API CloudStack
            $table->text('error_message')->nullable();                                                        // Penjelasan error jika langkah ini gagal
            $table->timestamp('started_at')->nullable();                                                      // Jam berapa langkah ini mulai dikerjakan
            $table->timestamp('completed_at')->nullable();                                                    // Jam berapa langkah ini selesai dikerjakan
            $table->timestamps();                                                                             // Waktu data tercatat di database
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('process_step_executions');
        Schema::dropIfExists('process_executions');
    }
};
