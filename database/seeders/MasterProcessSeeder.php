<?php

namespace Database\Seeders;

use App\Models\MasterProcess;
use App\Models\MasterProcessStep;
use Illuminate\Database\Seeder;

/**
 * Seeder untuk mengisi data master proses dan template langkah provisioning CloudStack.
 */
class MasterProcessSeeder extends Seeder
{
    /**
     * Menjalankan database seed.
     */
    public function run(): void
    {
        // 1. Buat master data proses deployment VM
        $process = MasterProcess::firstOrCreate(
            ['code' => 'deploy_vm'],
            [
                'name' => 'Deploy Virtual Machine',
                'description' => 'Workflow provisioning VM CloudStack lengkap (VPC, Subnet, ACL, VM, dan Static NAT).'
            ]
        );

        // 2. Definisi langkah, perintah API, tipe eksekusi, rollback, dan dependensi (depends_on)
        $steps = [
            [
                'step_name'        => 'create_vpc',           // Nama langkah 1: Buat VPC (Jaringan Utama)
                'command'          => 'createVpc',            // Perintah API CloudStack yang dipanggil
                'is_async'         => true,                   // Proses di server butuh waktu (asynchronous), jadi perlu ditunggu
                'rollback_command' => 'deleteVpc',            // Jika proses gagal nanti, hapus VPC ini agar tidak boros resource
                'order'            => 1,                      // Urutan ke-1
                'depends_on'       => null,                   // Tidak butuh syarat apa pun, langsung jalan pertama kali
            ],
            [
                'step_name'        => 'create_subnet',        // Nama langkah 2: Buat Subnet / Network di dalam VPC
                'command'          => 'createNetwork',        // Perintah API CloudStack
                'is_async'         => false,                  // Proses langsung selesai seketika (synchronous)
                'rollback_command' => 'deleteNetwork',        // Jika gagal nanti, hapus Subnet ini
                'order'            => 2,                      // Urutan ke-2
                'depends_on'       => ['create_vpc'],         // Syarat: Hanya bisa jalan setelah VPC selesai dibuat
            ],
            [
                'step_name'        => 'create_acl_list',      // Nama langkah 3: Buat Grup Aturan Keamanan (ACL List)
                'command'          => 'createNetworkACLList', // Perintah API CloudStack
                'is_async'         => true,                   // Proses asynchronous
                'rollback_command' => null,                   // ACL List tidak perlu dihapus manual saat rollback
                'order'            => 3,                      // Urutan ke-3
                'depends_on'       => ['create_vpc'],         // Syarat: Butuh VPC. (Bisa jalan bersamaan/paralel dengan create_subnet)
            ],
            [
                'step_name'        => 'create_acl_rule',      // Nama langkah 4: Isi aturan firewall (Rule) ke dalam ACL List
                'command'          => 'createNetworkACL',     // Perintah API CloudStack
                'is_async'         => true,                   // Proses asynchronous
                'rollback_command' => null,
                'order'            => 4,                      // Urutan ke-4
                'depends_on'       => ['create_acl_list'],    // Syarat: Hanya bisa jalan setelah ACL List selesai dibuat
            ],
            [
                'step_name'        => 'attach_acl_to_subnet', // Nama langkah 5: Pasangkan ACL List ke Subnet
                'command'          => 'replaceNetworkACLList', // Perintah API CloudStack
                'is_async'         => true,                   // Proses asynchronous
                'rollback_command' => null,
                'order'            => 5,                      // Urutan ke-5
                'depends_on'       => ['create_subnet', 'create_acl_list'], // Syarat: Butuh Subnet dan ACL List keduanya sudah beres
            ],
            [
                'step_name'        => 'deploy_vm',            // Nama langkah 6: Buat Server Virtual (VM)
                'command'          => 'deployVirtualMachine', // Perintah API CloudStack
                'is_async'         => true,                   // Proses asynchronous
                'rollback_command' => 'destroyVirtualMachine', // Jika gagal, hapus VM ini
                'order'            => 6,                      // Urutan ke-6
                'depends_on'       => ['create_subnet'],      // Syarat: Butuh Subnet selesai dibuat
            ],
            [
                'step_name'        => 'enable_static_nat',    // Nama langkah 7: Pasang IP Publik ke VM agar bisa diakses dari internet
                'command'          => 'enableStaticNat',      // Perintah API CloudStack
                'is_async'         => false,                  // Proses synchronous
                'rollback_command' => null,
                'order'            => 7,                      // Urutan ke-7
                'depends_on'       => ['deploy_vm', 'create_subnet'], // Syarat: Butuh VM dan Subnet selesai dibuat
            ],
        ];

        // 3. Simpan setiap langkah di atas ke tabel 'master_process_steps' di database
        foreach ($steps as $stepData)
        {
            MasterProcessStep::updateOrCreate(
                [
                    'master_process_id' => $process->id,
                    'step_name'         => $stepData['step_name']
                ],
                $stepData
            );
        }
    }
}
