<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\DataCenterAsset;
use App\Models\LaporanKerusakanDataCenter;
use App\Models\LaporanKerusakanHistory;
use App\Models\User;

class DataCenterSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Pastikan ada user Teknisi & Supervisor
        $andi = User::firstOrCreate(
            ['email' => 'andi.ramadhan@jabarprov.go.id'],
            [
                'name' => 'Andi Ramadhan',
                'password' => bcrypt('password'),
                'role' => 'admin',
                'is_active' => true,
            ]
        );

        $siti = User::firstOrCreate(
            ['email' => 'siti.nurhaliza@jabarprov.go.id'],
            [
                'name' => 'Siti Nurhaliza',
                'password' => bcrypt('password'),
                'role' => 'user',
                'is_active' => true,
            ]
        );

        $budi = User::firstOrCreate(
            ['email' => 'budi.setiawan@jabarprov.go.id'],
            [
                'name' => 'Budi Setiawan',
                'password' => bcrypt('password'),
                'role' => 'user',
                'is_active' => true,
            ]
        );

        $dewi = User::firstOrCreate(
            ['email' => 'dewi.anggraini@jabarprov.go.id'],
            [
                'name' => 'Dewi Anggraini',
                'password' => bcrypt('password'),
                'role' => 'user',
                'is_active' => true,
            ]
        );

        $rizky = User::firstOrCreate(
            ['email' => 'rizky.pratama@jabarprov.go.id'],
            [
                'name' => 'Rizky Pratama',
                'password' => bcrypt('password'),
                'role' => 'user',
                'is_active' => true,
            ]
        );

        // 2. Seed Master Aset Data Center sesuai Mockup
        $assetsData = [
            [
                'kode_aset' => 'DC-SRV-001',
                'nama_aset' => 'Server Dell PowerEdge R740',
                'kategori' => 'Server',
                'lokasi_rak' => 'Data Center Utama, Rak A-01 / U12',
                'status_operasional' => 'Maintenance',
                'penanggung_jawab_default' => 'Andi Ramadhan',
                'penanggung_jawab_id' => $andi->id,
            ],
            [
                'kode_aset' => 'DC-UPS-003',
                'nama_aset' => 'UPS APC Smart-UPS 3000',
                'kategori' => 'Power',
                'lokasi_rak' => 'Data Center Utama, Rak Power P-03',
                'status_operasional' => 'Rusak',
                'penanggung_jawab_default' => 'Siti Nurhaliza',
                'penanggung_jawab_id' => $siti->id,
            ],
            [
                'kode_aset' => 'DC-SW-002',
                'nama_aset' => 'Switch Cisco Catalyst 9300',
                'kategori' => 'Network',
                'lokasi_rak' => 'Data Center Utama, Rak N-02 / U24',
                'status_operasional' => 'Maintenance',
                'penanggung_jawab_default' => 'Budi Setiawan',
                'penanggung_jawab_id' => $budi->id,
            ],
            [
                'kode_aset' => 'DC-AC-001',
                'nama_aset' => 'AC Precision Vertiv PEX',
                'kategori' => 'Cooling',
                'lokasi_rak' => 'Data Center Utama, Koridor Dingin Unit 1',
                'status_operasional' => 'Operasional',
                'penanggung_jawab_default' => 'Dewi Anggraini',
                'penanggung_jawab_id' => $dewi->id,
            ],
            [
                'kode_aset' => 'DC-STR-002',
                'nama_aset' => 'Storage Synology RS3621',
                'kategori' => 'Storage',
                'lokasi_rak' => 'Data Center Utama, Rak S-02 / U10',
                'status_operasional' => 'Maintenance',
                'penanggung_jawab_default' => 'Rizky Pratama',
                'penanggung_jawab_id' => $rizky->id,
            ],
            [
                'kode_aset' => 'DC-RTR-001',
                'nama_aset' => 'Router MikroTik CCR2116',
                'kategori' => 'Network',
                'lokasi_rak' => 'Data Center Utama, Rak N-01 / U05',
                'status_operasional' => 'Rusak',
                'penanggung_jawab_default' => 'Budi Setiawan',
                'penanggung_jawab_id' => $budi->id,
            ],
        ];

        $assetsMap = [];
        foreach ($assetsData as $item) {
            $asset = DataCenterAsset::updateOrCreate(
                ['kode_aset' => $item['kode_aset']],
                $item
            );
            $assetsMap[$item['kode_aset']] = $asset;
        }

        // 3. Seed Laporan Kerusakan persis 6 data di Halaman 1 Mockup
        $reports = [
            [
                'nomor_laporan' => 'LKR-202610-0001',
                'tanggal' => '2026-10-16',
                'jam' => '09.30 WIB',
                'aset_id' => $assetsMap['DC-SRV-001']->id,
                'nama_aset' => 'Server Dell PowerEdge R740',
                'kode_aset' => 'DC-SRV-001',
                'lokasi_rak' => 'Data Center Utama, Rak A-01 / U12',
                'jenis_kerusakan' => 'Power supply unit (PSU) 1 tidak berfungsi. Indikator PSU berwarna jingga dan alarm perangkat aktif. Server tetap beroperasi menggunakan PSU 2.',
                'penyebab' => 'Diduga terjadi penurunan daya pada modul PSU 1 setelah fluktuasi tegangan listrik. Perlu pemeriksaan lanjutan oleh tim teknis.',
                'uraian_tindakan' => 'Melakukan pemeriksaan koneksi daya dan pengujian PSU 1. Menghubungi vendor untuk penggantian modul PSU. Memantau beban server melalui iDRAC selama proses perbaikan.',
                'target_selesai' => '2026-10-19',
                'pelapor_id' => $andi->id,
                'pelapor_nama' => 'Andi Ramadhan',
                'penanggung_jawab_id' => $andi->id,
                'penanggung_jawab_nama' => 'Andi Ramadhan',
                'status' => 'Diproses',
            ],
            [
                'nomor_laporan' => 'LKR-202610-0002',
                'tanggal' => '2026-10-15',
                'jam' => '14.15 WIB',
                'aset_id' => $assetsMap['DC-UPS-003']->id,
                'nama_aset' => 'UPS APC Smart-UPS 3000',
                'kode_aset' => 'DC-UPS-003',
                'lokasi_rak' => 'Data Center Utama, Rak Power P-03',
                'jenis_kerusakan' => 'Baterai UPS tidak menyimpan daya dan indikator replace battery berkedip merah terus menerus.',
                'penyebab' => 'Umur pakai baterai pack telah melebihi batas siklus pengisian (battery degradation).',
                'uraian_tindakan' => 'Menyiapkan modul baterai cadangan baru untuk proses hot-swap.',
                'target_selesai' => '2026-10-18',
                'pelapor_id' => $siti->id,
                'pelapor_nama' => 'Siti Nurhaliza',
                'penanggung_jawab_id' => $siti->id,
                'penanggung_jawab_nama' => 'Siti Nurhaliza',
                'status' => 'Baru',
            ],
            [
                'nomor_laporan' => 'LKR-202610-0003',
                'tanggal' => '2026-10-14',
                'jam' => '10.45 WIB',
                'aset_id' => $assetsMap['DC-SW-002']->id,
                'nama_aset' => 'Switch Cisco Catalyst 9300',
                'kode_aset' => 'DC-SW-002',
                'lokasi_rak' => 'Data Center Utama, Rak N-02 / U24',
                'jenis_kerusakan' => 'Port SFP+ uplink 1 flapping dan sering packet loss tinggi.',
                'penyebab' => 'SFP transceiver optic mengalami panas berlebih (overheat) atau kabel fiber optik kotor.',
                'uraian_tindakan' => 'Membersihkan patch cord fiber optic dan melakukan monitoring link optic dBm level.',
                'target_selesai' => '2026-10-17',
                'pelapor_id' => $budi->id,
                'pelapor_nama' => 'Budi Setiawan',
                'penanggung_jawab_id' => $budi->id,
                'penanggung_jawab_nama' => 'Budi Setiawan',
                'status' => 'Diproses',
            ],
            [
                'nomor_laporan' => 'LKR-202610-0004',
                'tanggal' => '2026-10-13',
                'jam' => '08.20 WIB',
                'aset_id' => $assetsMap['DC-AC-001']->id,
                'nama_aset' => 'AC Precision Vertiv PEX',
                'kode_aset' => 'DC-AC-001',
                'lokasi_rak' => 'Data Center Utama, Koridor Dingin Unit 1',
                'jenis_kerusakan' => 'Sensor suhu membaca deviasi temperatur ruangan naik dari 20°C menjadi 25°C.',
                'penyebab' => 'Filter udara tersumbat debu dan kondensor outdoor memerlukan pembersihan.',
                'uraian_tindakan' => 'Pembersihan filter udara dan pengisian freon R410A bersama tim teknisi vendor HVAC.',
                'target_selesai' => '2026-10-14',
                'tanggal_selesai_aktual' => '2026-10-14 11:30:00',
                'pelapor_id' => $dewi->id,
                'pelapor_nama' => 'Dewi Anggraini',
                'penanggung_jawab_id' => $dewi->id,
                'penanggung_jawab_nama' => 'Dewi Anggraini',
                'supervisor_id' => $andi->id,
                'supervisor_nama' => 'Andi Ramadhan',
                'status' => 'Selesai',
                'catatan_validasi' => 'Pemeriksaan suhu koridor dingin sudah stabil di 19.5°C. Valid.',
                'validated_at' => '2026-10-14 13:00:00',
            ],
            [
                'nomor_laporan' => 'LKR-202610-0005',
                'tanggal' => '2026-10-12',
                'jam' => '13.00 WIB',
                'aset_id' => $assetsMap['DC-STR-002']->id,
                'nama_aset' => 'Storage Synology RS3621',
                'kode_aset' => 'DC-STR-002',
                'lokasi_rak' => 'Data Center Utama, Rak S-02 / U10',
                'jenis_kerusakan' => 'Hard Disk Drive slot #4 berstatus Warning (Bad Sector terdeteksi 48 sectors).',
                'penyebab' => 'Degradasi disk mekanik normal karena masa operasional 3 tahun.',
                'uraian_tindakan' => 'Melakukan penggantian harddisk spare SAS 8TB Enterprise dan proses rebuild array RAID 6.',
                'target_selesai' => '2026-10-16',
                'pelapor_id' => $rizky->id,
                'pelapor_nama' => 'Rizky Pratama',
                'penanggung_jawab_id' => $rizky->id,
                'penanggung_jawab_nama' => 'Rizky Pratama',
                'status' => 'Diproses',
            ],
            [
                'nomor_laporan' => 'LKR-202610-0006',
                'tanggal' => '2026-10-12',
                'jam' => '11.10 WIB',
                'aset_id' => $assetsMap['DC-RTR-001']->id,
                'nama_aset' => 'Router MikroTik CCR2116',
                'kode_aset' => 'DC-RTR-001',
                'lokasi_rak' => 'Data Center Utama, Rak N-01 / U05',
                'jenis_kerusakan' => 'Fan cooling 2 speed drop di bawah 1500 RPM memicu warning hardware health.',
                'penyebab' => 'Bearing fan aus akibat operasi continue.',
                'uraian_tindakan' => 'Pembersihan heatsink dan penggantian modul kipas cadangan.',
                'target_selesai' => '2026-10-15',
                'pelapor_id' => $budi->id,
                'pelapor_nama' => 'Budi Setiawan',
                'penanggung_jawab_id' => $budi->id,
                'penanggung_jawab_nama' => 'Budi Setiawan',
                'status' => 'Baru',
            ],
        ];

        foreach ($reports as $rep) {
            $created = LaporanKerusakanDataCenter::updateOrCreate(
                ['nomor_laporan' => $rep['nomor_laporan']],
                $rep
            );

            // Seed initial history
            LaporanKerusakanHistory::firstOrCreate(
                [
                    'laporan_id' => $created->id,
                    'action' => 'Pembuatan Laporan',
                ],
                [
                    'user_id' => $created->pelapor_id,
                    'status_from' => null,
                    'status_to' => $created->status,
                    'catatan' => 'Laporan kerusakan dibuat dengan status ' . $created->status,
                ]
            );
        }
    }
}
