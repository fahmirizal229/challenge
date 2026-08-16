<?php

namespace App\Jobs;

use App\Models\ProcessExecution;
use App\Models\ProcessStepExecution;
use App\Services\CloudStackClient;

/**
 * Job khusus untuk menautkan / memasangkan Grup ACL Keamanan ke Subnet (replaceNetworkACLList).
 */
class AttachAclToSubnetJob extends BaseStepJob
{
    /**
     * Menyiapkan data pasangan ID ACL List dan ID Subnet.
     */
    protected function buildPayload(ProcessStepExecution $step, ProcessExecution $execution, CloudStackClient $client): array
    {
        // Cari ID ACL List dari langkah create_acl_list
        $acl_step = $execution->stepExecutions()->where('step_name', 'create_acl_list')->first();
        $acl_id = $acl_step ? ($acl_step->response_payload['queryasyncjobresultresponse']['jobresult']['networkacllist']['id'] ?? $acl_step->response_payload['createnetworkacllistresponse']['networkacllist']['id'] ?? null) : null;

        // Cari ID Subnet dari langkah create_subnet
        $subnet_step = $execution->stepExecutions()->where('step_name', 'create_subnet')->first();
        $subnet_id = $subnet_step ? ($subnet_step->response_payload['queryasyncjobresultresponse']['jobresult']['network']['id'] ?? $subnet_step->response_payload['createnetworkresponse']['network']['id'] ?? null) : null;

        return [
            'aclid'     => $acl_id,     // ID ACL List
            'networkid' => $subnet_id,  // ID Subnet
        ];
    }
}
