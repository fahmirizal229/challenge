<?php

namespace App\Jobs;

use App\Models\ProcessExecution;
use App\Models\ProcessStepExecution;
use App\Services\CloudStackClient;

/**
 * Job khusus untuk langkah pembuatan Subnet / Network di dalam VPC.
 */
class CreateSubnetJob extends BaseStepJob
{
    /**
     * Menyiapkan data ID VPC, nama Subnet, Gateway, dan Netmask.
     */
    protected function buildPayload(ProcessStepExecution $step, ProcessExecution $execution, CloudStackClient $client): array
    {
        $id_suffix = substr($execution->id, -6);

        // Cari ID VPC dari langkah create_vpc yang sudah selesai
        $vpc_step = $execution->stepExecutions()->where('step_name', 'create_vpc')->first();
        $vpc_id = $vpc_step ? ($vpc_step->response_payload['queryasyncjobresultresponse']['jobresult']['vpc']['id'] ?? $vpc_step->response_payload['createvpcresponse']['vpc']['id'] ?? null) : null;

        return [
            'vpcid'   => $vpc_id,
            'name'    => "Subnet-{$id_suffix}",
            'gateway' => '10.0.1.1',
            'netmask' => '255.255.255.0',
        ];
    }
}
