<?php

namespace App\Jobs;

use App\Models\ProcessExecution;
use App\Models\ProcessStepExecution;
use App\Services\CloudStackClient;

/**
 * Job khusus untuk membuat Server Virtual Machine (VM) di CloudStack.
 */
class DeployVmJob extends BaseStepJob
{
    /**
     * Menyiapkan data spesifikasi VM: nama VM, Subnet ID, paket kapasitas (offering), dan template OS.
     */
    protected function buildPayload(ProcessStepExecution $step, ProcessExecution $execution, CloudStackClient $client): array
    {
        $id_suffix = substr($execution->id, -6);

        // Cari ID Subnet dari langkah create_subnet
        $subnet_step = $execution->stepExecutions()->where('step_name', 'create_subnet')->first();
        $subnet_id = $subnet_step ? ($subnet_step->response_payload['queryasyncjobresultresponse']['jobresult']['network']['id'] ?? $subnet_step->response_payload['createnetworkresponse']['network']['id'] ?? null) : null;

        return [
            'networkids'        => $subnet_id,              // Menghubungkan VM ke Subnet yang sudah dibuat
            'serviceofferingid' => 'mock-service-offering',  // Paket CPU & RAM
            'templateid'        => 'mock-template',          // Sistem Operasi (OS Template)
            'name'              => "VM-{$id_suffix}",         // Nama Server VM
        ];
    }
}
