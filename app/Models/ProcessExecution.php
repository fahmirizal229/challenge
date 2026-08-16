<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Model ini mewakili tabel 'process_executions' di database.
 * Setiap kali user meminta pembuatan VM, satu baris data akan dicatat melalui Model ini.
 */
class ProcessExecution extends Model
{
    use HasFactory, HasUlids;

    // Daftar kolom tabel yang boleh diisi datanya secara langsung
    protected $fillable = [
        'master_process_id',
        'status',
        'public_ip',
        'fail_at',
        'timeout_at',
        'delay_seconds',
        'timeout_seconds',
        'resources',
        'error_message',
    ];

    // Mengubah tipe data kolom database secara otomatis saat dibaca di PHP
    protected $casts = [
        'public_ip'       => 'boolean',
        'delay_seconds'   => 'integer',
        'timeout_seconds' => 'integer',
        'resources'       => 'array',   // Otomatis diubah dari JSON text menjadi Array PHP
    ];

    /**
     * Relasi ke template proses induk (MasterProcess).
     */
    public function masterProcess(): BelongsTo
    {
        return $this->belongsTo(MasterProcess::class, 'master_process_id');
    }

    /**
     * Relasi ke semua catatan langkah (ProcessStepExecution) yang diurutkan berdasarkan urutan pengerjaan.
     */
    public function stepExecutions(): HasMany
    {
        return $this->hasMany(ProcessStepExecution::class, 'process_execution_id')->orderBy('order');
    }

    /**
     * Mengambil ID resource yang tersimpan (misal: vpc_id, subnet_id, vm_id).
     */
    public function getResource(string $key): ?string
    {
        return $this->resources[$key] ?? null;
    }

    /**
     * Menyimpan ID resource baru ke dalam kolom JSON resources.
     */
    public function setResource(string $key, ?string $value): void
    {
        $resources = $this->resources ?? [];
        $resources[$key] = $value;
        $this->resources = $resources;
        $this->save();
    }
}
