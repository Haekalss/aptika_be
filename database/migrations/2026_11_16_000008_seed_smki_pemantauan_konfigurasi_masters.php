<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seed master resmi FR-048 (Pemantauan Manajemen Konfigurasi).
 * Idempoten (updateOrInsert) — aman dijalankan ulang.
 * Data = Lampiran A di FR-048\PHASE_PLAN.md; menunggu pengesahan pemilik proses (SANDIKAMI).
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $jenis = [
            ['nama' => 'Server', 'keterangan' => 'Server fisik & virtual (VM) — hardware inti'],
            ['nama' => 'Jaringan', 'keterangan' => 'Perangkat jaringan & keamanan (switch/router/firewall)'],
            ['nama' => 'Komputer / Laptop', 'keterangan' => 'Perangkat endpoint pengguna'],
            ['nama' => 'Aplikasi / Sistem', 'keterangan' => 'Aplikasi & sistem informasi yang dipantau'],
        ];
        foreach ($jenis as $row) {
            DB::table('smki_jenis_konfigurasis')->updateOrInsert(
                ['nama' => $row['nama']],
                ['keterangan' => $row['keterangan'], 'is_active' => true, 'updated_at' => $now, 'created_at' => $now]
            );
        }

        $jenisId = DB::table('smki_jenis_konfigurasis')->pluck('id', 'nama');

        $objeks = [
            ['nama' => 'SRV-PROD-01', 'jenis' => 'Server'],
            ['nama' => 'SRV-DB-01', 'jenis' => 'Server'],
            ['nama' => 'SW-CORE-01', 'jenis' => 'Jaringan'],
            ['nama' => 'FW-EDGE-01', 'jenis' => 'Jaringan'],
            ['nama' => 'PC-APTIKA-01', 'jenis' => 'Komputer / Laptop'],
            ['nama' => 'SI-Kepegawaian', 'jenis' => 'Aplikasi / Sistem'],
        ];
        foreach ($objeks as $row) {
            DB::table('smki_objeks')->updateOrInsert(
                ['nama' => $row['nama']],
                ['jenis_konfigurasi_id' => $jenisId[$row['jenis']], 'is_active' => true, 'updated_at' => $now, 'created_at' => $now]
            );
        }

        $objekId = DB::table('smki_objeks')->pluck('id', 'nama');

        $standars = [
            'SRV-PROD-01' => [
                'Sistem Operasi' => 'Ubuntu 22.04 LTS',
                'SSH Root Login' => 'disabled',
                'Min Password Length' => '12',
                'Firewall Aktif' => 'ya',
                'NTP Server' => 'aktif',
                'Banner Login' => 'aktif',
                'Patching OS' => 'maks 30 hari terakhir',
            ],
            'SRV-DB-01' => [
                'DBMS' => 'PostgreSQL 15',
                'Backup Terjadwal' => 'harian',
                'Akses Remote DB' => 'dibatasi subnet internal',
                'Audit Log DB' => 'aktif',
            ],
            'SW-CORE-01' => [
                'NTP Server' => 'aktif',
                'Banner Login' => 'aktif',
                'SNMP Community' => 'bukan public',
                'Firmware Up-to-date' => 'ya',
            ],
            'FW-EDGE-01' => [
                'Ruleset Terakhir Diubah' => 'terdokumentasi',
                'Default Deny' => 'ya',
                'Logging' => 'remote syslog',
                'Firmware Up-to-date' => 'ya',
            ],
            'PC-APTIKA-01' => [
                'Antivirus' => 'aktif & update',
                'Disk Encryption' => 'aktif',
                'OS Terbaru' => 'ya',
                'Patching' => 'maks 14 hari',
            ],
            'SI-Kepegawaian' => [
                'Terdaftar di Daftar Aset' => 'ya',
                'SSO/LDAP' => 'terhubung',
                'TLS' => 'TLS 1.2+',
                'Backup' => 'harian',
            ],
        ];
        foreach ($standars as $objek => $rows) {
            foreach ($rows as $namaKonfigurasi => $nilaiStandar) {
                DB::table('smki_nilai_standars')->updateOrInsert(
                    ['objek_id' => $objekId[$objek], 'nama_konfigurasi' => $namaKonfigurasi],
                    ['nilai_standar' => $nilaiStandar, 'is_active' => true, 'updated_at' => $now, 'created_at' => $now]
                );
            }
        }
    }

    public function down(): void
    {
        // Tidak menghapus master — data resmi yang ter-seed tetap utuh.
    }
};
