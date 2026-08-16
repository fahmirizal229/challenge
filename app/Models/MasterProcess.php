<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Model ini mewakili tabel 'master_processes' di database.
 * Menyimpan daftar jenis alur kerja yang ada di sistem (contoh: deploy_vm, backup_vm, dll).
 */
class MasterProcess extends Model
{
    use HasFactory, HasUlids;

    // Kolom-kolom yang boleh diisi
    protected $fillable = [
        'code',        // Kode unik proses, contoh: 'deploy_vm'
        'name',        // Nama proses, contoh: 'Deploy Virtual Machine'
        'description', // Penjelasan ringkas mengenai proses ini
    ];

    /**
     * Relasi ke seluruh daftar langkah kerja (MasterProcessStep) yang diurutkan dari nomor 1.
     */
    public function steps(): HasMany
    {
        return $this->hasMany(MasterProcessStep::class, 'master_process_id')->orderBy('order');
    }

    /**
     * Relasi ke semua riwayat transaksi eksekusi yang pernah dibuat dari template proses ini.
     */
    public function executions(): HasMany
    {
        return $this->hasMany(ProcessExecution::class, 'master_process_id');
    }
}
