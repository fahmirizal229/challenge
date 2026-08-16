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
        // 1. Tabel 'master_processes': Menyimpan jenis proses apa saja yang tersedia (misal: 'deploy_vm', 'backup_vm')
        Schema::create('master_processes', function (Blueprint $table)
        {
            $table->ulid('id')->primary();               // ID unik setiap proses dalam format teks acak ULID
            $table->string('code')->unique();            // Kode unik untuk dipanggil sistem, contoh: 'deploy_vm'
            $table->string('name');                      // Nama proses yang mudah dibaca manusia, contoh: 'Deploy Virtual Machine'
            $table->text('description')->nullable();     // Penjelasan ringkas mengenai proses ini
            $table->timestamps();                        // Waktu data dibuat dan diubah (created_at & updated_at)
        });

        // 2. Tabel 'master_process_steps': Menyimpan daftar langkah-langkah kerja untuk setiap master proses
        Schema::create('master_process_steps', function (Blueprint $table)
        {
            $table->ulid('id')->primary();                                                            // ID unik setiap langkah
            $table->foreignUlid('master_process_id')->constrained('master_processes')->cascadeOnDelete(); // Menghubungkan langkah ini ke proses induknya
            $table->string('step_name');                                                              // Nama langkah, contoh: 'create_vpc'
            $table->string('command');                                                                // Perintah API CloudStack yang harus dipanggil, contoh: 'createVpc'
            $table->boolean('is_async')->default(false);                                              // true = proses butuh waktu di server (async), false = langsung selesai seketika
            $table->string('rollback_command')->nullable();                                           // Perintah untuk membatalkan/menghapus resource jika terjadi kegagalan
            $table->integer('order')->default(1);                                                     // Urutan nomor langkah (1, 2, 3, dst.)
            $table->json('depends_on')->nullable();                                                   // Syarat langkah lain yang harus berstatus 'success' sebelum langkah ini boleh jalan
            $table->timestamps();                                                                     // Waktu dibuat & diupdate
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('master_process_steps');
        Schema::dropIfExists('master_processes');
    }
};
