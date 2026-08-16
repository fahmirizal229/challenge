<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model ini mewakili tabel 'master_process_steps' di database.
 * Menyimpan resep / panduan setiap langkah: perintah CloudStack apa yang harus dijalankan,
 * perintah rollback apa jika gagal, dan langkah mana saja yang harus selesai duluan (depends_on).
 */
class MasterProcessStep extends Model
{
    use HasFactory, HasUlids;

    // Kolom-kolom yang boleh diisi
    protected $fillable = [
        'master_process_id', // Menghubungkan langkah ini ke proses induknya
        'step_name',         // Nama langkah, contoh: 'create_vpc'
        'command',           // Perintah API CloudStack, contoh: 'createVpc'
        'is_async',          // Apakah proses butuh waktu tunggu di server (true/false)
        'rollback_command',  // Perintah pembatalan, contoh: 'deleteVpc'
        'order',             // Urutan pengerjaan
        'depends_on',        // Syarat langkah yang harus 'success' sebelum langkah ini jalan
    ];

    // Konversi tipe data otomatis
    protected $casts = [
        'is_async'   => 'boolean',
        'order'      => 'integer',
        'depends_on' => 'array',   // Otomatis diubah dari JSON text menjadi Array PHP
    ];

    /**
     * Relasi kembali ke MasterProcess induknya.
     */
    public function masterProcess(): BelongsTo
    {
        return $this->belongsTo(MasterProcess::class, 'master_process_id');
    }
}
