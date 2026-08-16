<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model ini mewakili tabel 'process_step_executions' di database.
 * Berfungsi sebagai buku catatan audit (Audit Log) untuk merekam setiap aktivitas langkah kerja:
 * Statusnya apa, pesan errornya apa, berapa kali dicoba ulang, jam mulai dan jam selesainya.
 */
class ProcessStepExecution extends Model
{
    use HasFactory, HasUlids;

    // Kolom-kolom yang boleh diisi
    protected $fillable = [
        'process_execution_id',
        'master_process_step_id',
        'step_name',
        'command',
        'is_async',
        'rollback_command',
        'order',
        'depends_on',
        'status',
        'cloudstack_job_id',
        'attempt',
        'request_payload',
        'response_payload',
        'error_message',
        'started_at',
        'completed_at',
    ];

    // Konversi tipe data otomatis
    protected $casts = [
        'is_async'         => 'boolean',
        'order'            => 'integer',
        'depends_on'       => 'array',
        'attempt'          => 'integer',
        'request_payload'  => 'array',   // Otomatis diubah dari JSON text menjadi Array PHP
        'response_payload' => 'array',   // Otomatis diubah dari JSON text menjadi Array PHP
        'started_at'       => 'datetime',
        'completed_at'     => 'datetime',
    ];

    /**
     * Relasi ke proses eksekusi induknya.
     */
    public function processExecution(): BelongsTo
    {
        return $this->belongsTo(ProcessExecution::class, 'process_execution_id');
    }

    /**
     * Relasi ke definisi template master langkahnya.
     */
    public function masterProcessStep(): BelongsTo
    {
        return $this->belongsTo(MasterProcessStep::class, 'master_process_step_id');
    }
}
