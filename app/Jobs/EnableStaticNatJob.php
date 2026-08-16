<?php

namespace App\Jobs;

use App\Models\ProcessExecution;
use App\Models\ProcessStepExecution;
use App\Services\CloudStackClient;
use Exception;

/**
 * Job khusus untuk mengambil IP Publik yang masih bebas (Free) dan memasangkannya ke VM (Static NAT).
 */
class EnableStaticNatJob extends BaseStepJob
{
    /**
     * Mengambil daftar Public IP bebas, memilih satu IP, lalu menyiapkan parameter untuk Static NAT.
     */
    protected function buildPayload(ProcessStepExecution $step, ProcessExecution $execution, CloudStackClient $client): array
    {
        // 1. Tanyakan ke CloudStack: "Apakah ada IP Publik yang nganggur/bebas (state = Free)?"
        $list_ip_res = $client->request(['command' => 'listPublicIpAddresses', 'state' => 'Free']);
        $ips = $list_ip_res['listpublicipaddressesresponse']['publicipaddress'] ?? [];

        // Jika semua IP publik sedang terpakai/habis
        if (empty($ips))
        {
            throw new Exception("Tidak ada alamat Public IP gratis (Free) yang tersedia di CloudStack.");
        }

        // 2. Ambil IP publik pertama yang tersedia
        $selected_ip = $ips[0];

        // Cari ID Subnet dan ID VM
        $subnet_step = $execution->stepExecutions()->where('step_name', 'create_subnet')->first();
        $subnet_id = $subnet_step ? ($subnet_step->response_payload['queryasyncjobresultresponse']['jobresult']['network']['id'] ?? $subnet_step->response_payload['createnetworkresponse']['network']['id'] ?? null) : null;

        $vm_step = $execution->stepExecutions()->where('step_name', 'deploy_vm')->first();
        $vm_id = $vm_step ? ($vm_step->response_payload['queryasyncjobresultresponse']['jobresult']['virtualmachine']['id'] ?? $vm_step->response_payload['deployvirtualmachineresponse']['virtualmachine']['id'] ?? null) : null;

        // 3. Kembalikan data untuk perintah enableStaticNat
        return [
            'networkid'        => $subnet_id,          // ID Subnet
            'virtualmachineid' => $vm_id,              // ID VM tujuan
            'ipaddressid'      => $selected_ip['id'],  // ID IP Publik yang dipasangkan
        ];
    }
}
