<?php

namespace App\Jobs;

use App\Models\ProcessExecution;
use App\Models\ProcessStepExecution;
use App\Services\CloudStackClient;

/**
 * Job khusus untuk langkah pembuatan Grup Aturan Keamanan (Network ACL List).
 */
class CreateAclListJob extends BaseStepJob
{
    /**
     * Menyiapkan data VPC ID dan nama ACL List.
     */
    protected function buildPayload(ProcessStepExecution $step, ProcessExecution $execution, CloudStackClient $client): array
    {
        $id_suffix = substr($execution->id, -6);

        // Cari ID VPC dari langkah create_vpc
        $vpc_step = $execution->stepExecutions()->where('step_name', 'create_vpc')->first();
        $vpc_id = $vpc_step ? ($vpc_step->response_payload['queryasyncjobresultresponse']['jobresult']['vpc']['id'] ?? $vpc_step->response_payload['createvpcresponse']['vpc']['id'] ?? null) : null;

        return [
            'vpcid' => $vpc_id,
            'name'  => "ACL-{$id_suffix}",
        ];
    }
}
