# CloudStack VM Deployment Workflow Engine

Aplikasi workflow engine berbasis **Laravel Queue** dan **Master Data Database** untuk mengotomatiskan proses provisioning infrastruktur CloudStack Virtual Machine (VPC, Subnet, ACL, VM, dan Public IP/Static NAT) secara paralel, andal (retry on timeout), serta aman (backward rollback otomatis saat terjadi kegagalan).

---

## 🚀 Fitur Utama

- **100% Database-Driven Workflow & Dependencies**: 
  - Seluruh alur kerja, nama perintah API CloudStack, serta syarat dependensi antar-langkah didefinisikan secara fleksibel di tabel database (`master_processes` dan `master_process_steps` via kolom `depends_on`).
  - Menambah step baru atau alur baru tidak memerlukan modifikasi kode utama (`WorkflowEngine`).
- **Parallel Step Execution**: Menjalankan langkah-langkah yang syarat dependensinya sudah terpenuhi secara bersamaan (misal: *Subnet* dan *ACL List* berjalan bersamaan setelah *VPC* siap; *VM*, *ACL Rule*, dan *Attach ACL* berjalan bersamaan setelah dependensi masing-masing terpenuhi).
- **Non-blocking Waiting & Requeue (`release(5)`)**: Pengecekan status pekerjaan async di CloudStack dilakukan secara non-blocking setiap 5 detik tanpa membebani thread antrean atau CPU.
- **Unlimited Tries (`$tries = 0`)**: Antrean Laravel tidak membatasi kuota percobaan sehingga proses yang memakan waktu lama di server CloudStack tetap dapat berjalan hingga tuntas.
- **5x Timeout Retry**: Otomatis melakukan percobaan ulang hingga 5 kali jika request API mengalami koneksi lambat/timeout sebelum menandai langkah gagal.
- **Dynamic Reverse Rollback**: Jika salah satu langkah gagal, sistem secara otomatis mengekstrak ID resource yang sempat terbuat dan menghapusnya dengan urutan mundur (*VM* → *Subnet* → *VPC*) berdasarkan kolom `rollback_command` di database.
- **Real-time Terminal Watcher**: Menampilkan progres eksekusi langkah secara langsung (live monitor) di console terminal.

---

## 📋 Persyaratan & Konfigurasi

### 1. Migrasi & Seeder Master Data
Pastikan tabel database dan data master step sudah terisi:
```bash
php artisan migrate --seed
```

### 2. Jalankan Queue Worker
Sistem menggunakan antrean database Laravel untuk pengecekan status dan eksekusi paralel. Jalankan worker di terminal terpisah:
```bash
php artisan queue:listen --tries=100 --timeout=0
```

---

## 🛠️ Cara Penggunaan (CLI Commands)

Perintah utama untuk memicu pembuatan VM adalah:
```bash
php artisan deploy:vm [options]
```

### Opsi Perintah yang Tersedia:
| Opsi | Tipe | Deskripsi | Default |
|---|---|---|---|
| `--public-ip` | Flag | Alokasikan Public IP gratis dan konfigurasi Static NAT ke VM | `false` |
| `--watch` | Flag | Pantau progres eksekusi langkah secara live di terminal | `false` |
| `--fail-at=` | String | Simulasikan kegagalan (`jobstatus = 2`) pada langkah tertentu (misal: `deploy_vm`) | `null` |
| `--timeout-at=`| String | Simulasikan timeout pada langkah tertentu (misal: `create_vpc`) | `null` |
| `--delay=` | Int | Delay sinkronisasi (detik) pada mock server CloudStack | `2` |
| `--timeout=` | Int | Durasi batas waktu (detik) untuk simulasi timeout | `35` |

---

## 📖 Contoh Skenario Penggunaan

### 1. Deploy VM Lengkap dengan Public IP & Static NAT
Menjalankan seluruh alur hingga alokasi Public IP dan Static NAT:
```bash
php artisan deploy:vm --public-ip --watch
```

### 2. Deploy VM Standar (Tanpa Public IP)
Menjalankan pembuatan VPC, Subnet, ACL, dan VM. Langkah `enable_static_nat` otomatis ditandai `SKIPPED`:
```bash
php artisan deploy:vm --watch
```

### 3. Simulasi Kegagalan & Rollback Otomatis
Mensimulasikan error pada langkah `deploy_vm`. Sistem akan otomatis membatalkan dan menghapus resource yang sudah terlanjur dibuat secara mundur (*Subnet* → *VPC*):
```bash
php artisan deploy:vm --fail-at=deploy_vm --watch
```

### 4. Simulasi Timeout & Mekanisme Retry 5x
Mensimulasikan request timeout pada langkah `create_vpc`. Sistem akan mencoba ulang (retry) sebanyak 5 kali dengan jeda 5 detik sebelum melakukan rollback:
```bash
php artisan deploy:vm --timeout-at=create_vpc --timeout=7 --watch
```

---

## 🔍 Memeriksa Status & Audit Trail Proses

Anda dapat memeriksa status proses dan jejak audit (Audit Trail) dari setiap langkah kapan saja menggunakan perintah `vm:status`:

```bash
php artisan vm:status <PROCESS_ID>
```

**Contoh Output:**
```text
=================================================
  Detail Eksekusi Proses: 01m04j8c62tkbf4je46k11vpx6
=================================================
Status:      SUCCESS
Public IP:   YES
Fail At:     None
Timeout At:  None
Created At:  2026-08-16 05:56:01
Updated At:  2026-08-16 05:56:31

--- Step Audit Trail ---
+-------+----------------------+-----------------------+-------+---------+----------+--------------------------------------+------------+--------------+
| Order | Step Name            | Command               | Async | Status  | Attempts | Job ID                               | Started At | Completed At |
+-------+----------------------+-----------------------+-------+---------+----------+--------------------------------------+------------+--------------+
| 1     | create_vpc           | createVpc             | YES   | SUCCESS | -        | fcb79c5a-7e8c-4eb9-a851-06ab7a5376a6 | 05:56:02   | 05:56:08     |
| 2     | create_subnet        | createNetwork         | NO    | SUCCESS | -        | -                                    | 05:56:08   | 05:56:11     |
| 3     | create_acl_list      | createNetworkACLList  | YES   | SUCCESS | -        | 8cc94462-de92-4c3c-a743-98b6af12b88d | 05:56:08   | 05:56:19     |
| 4     | create_acl_rule      | createNetworkACL      | YES   | SUCCESS | -        | 401fd356-6602-4df8-8881-a174b13cd661 | 05:56:19   | 05:56:30     |
| 5     | attach_acl_to_subnet | replaceNetworkACLList | YES   | SUCCESS | -        | 45b20a46-a6f5-496c-9cf2-7217dcef679a | 05:56:19   | 05:56:31     |
| 6     | deploy_vm            | deployVirtualMachine  | YES   | SUCCESS | -        | 5e7943ea-c879-41ba-8acf-b5a5eb1550ca | 05:56:11   | 05:56:21     |
| 7     | enable_static_nat    | enableStaticNat       | NO    | SUCCESS | -        | -                                    | 05:56:21   | 05:56:28     |
+-------+----------------------+-----------------------+-------+---------+----------+--------------------------------------+------------+--------------+

--- Provisioned Steps ---
 - create_vpc: SUCCESS
 - create_subnet: SUCCESS
 - create_acl_list: SUCCESS
 - create_acl_rule: SUCCESS
 - attach_acl_to_subnet: SUCCESS
 - deploy_vm: SUCCESS
 - enable_static_nat: SUCCESS
```
