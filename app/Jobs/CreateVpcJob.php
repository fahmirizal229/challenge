<?php

namespace App\Jobs;

use App\Models\ProcessExecution;
use App\Models\ProcessStepExecution;
use App\Services\CloudStackClient;

/**
 * Job khusus untuk langkah pembuatan VPC (Virtual Private Cloud / Jaringan Utama).
 */
class CreateVpcJob extends BaseStepJob
{
    /**
     * Menyiapkan data nama VPC dan blok IP (CIDR) yang akan dikirim ke CloudStack.
     */
    protected function buildPayload(ProcessStepExecution $step, ProcessExecution $execution, CloudStackClient $client): array
    {
        // Ambil 6 karakter terakhir dari ID proses agar nama VPC unik (contoh: VPC-k2p3ab)
        $id_suffix = substr($execution->id, -6);

        return [
            'name' => "VPC-{$id_suffix}",
            'cidr' => '10.0.0.0/16',
        ];
    }
}
