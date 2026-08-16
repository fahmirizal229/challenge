<?php

namespace App\Jobs;

use App\Models\ProcessExecution;
use App\Models\ProcessStepExecution;
use App\Services\CloudStackClient;

/**
 * Job khusus untuk memasukkan aturan firewall (Rule) ke dalam ACL List.
 */
class CreateAclRuleJob extends BaseStepJob
{
    /**
     * Menyiapkan data ACL ID, protokol TCP, aksi 'allow' (izinkan), dan jangkauan IP (0.0.0.0/0).
     */
    protected function buildPayload(ProcessStepExecution $step, ProcessExecution $execution, CloudStackClient $client): array
    {
        // Cari ID ACL List dari langkah create_acl_list
        $acl_step = $execution->stepExecutions()->where('step_name', 'create_acl_list')->first();
        $acl_id = $acl_step ? ($acl_step->response_payload['queryasyncjobresultresponse']['jobresult']['networkacllist']['id'] ?? $acl_step->response_payload['createnetworkacllistresponse']['networkacllist']['id'] ?? null) : null;

        return [
            'aclid'    => $acl_id,
            'protocol' => 'tcp',
            'action'   => 'allow',
            'cidrlist' => '0.0.0.0/0',
        ];
    }
}
