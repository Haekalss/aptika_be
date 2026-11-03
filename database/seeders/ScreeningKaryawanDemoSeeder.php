<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Data DUMMY screening karyawan (24 record contoh) - HANYA untuk development/demo.
 * Jalankan manual: php artisan db:seed --class=ScreeningKaryawanDemoSeeder
 */
class ScreeningKaryawanDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (DB::table('screening_karyawan')->exists()) {
            $this->command?->warn('Tabel screening_karyawan sudah berisi data, seeder dilewati.');
            return;
        }

        // Seed Initial Mockup Records (24 Records matching the screenshots)
        $initialData = [
            [
                'nama' => 'Andi Pratama',
                'jabatan' => 1, // Staff IT
                'bidang' => 1, // Aptika
                'tahun' => 2026,
                'tgl' => '2026-04-16',
                'kesimpulan' => 'Administrasi Lengkap',
                'catatan' => 'Karyawan memenuhi semua kriteria dokumen yang dipersyaratkan. Referensi telah diverifikasi secara internal.',
                'items' => [
                    ['status' => 'Ada', 'ket' => '-'],
                    ['status' => 'Ada', 'ket' => '-'],
                    ['status' => 'Ada', 'ket' => 'Verified via phone'],
                    ['status' => 'Ada', 'ket' => '-'],
                    ['status' => 'Tidak', 'ket' => 'Tidak ada catatan tambahan'],
                ]
            ],
            [
                'nama' => 'Siti Nurhaliza',
                'jabatan' => 2, // Admin
                'bidang' => 1, // Aptika
                'tahun' => 2026,
                'tgl' => '2026-04-15',
                'kesimpulan' => 'Perlu melengkapi administrasi',
                'catatan' => 'Ijazah asli belum dilegalisir, mohon dilengkapi dalam 7 hari kerja.',
                'items' => [
                    ['status' => 'Ada', 'ket' => 'KTP & KK Valid'],
                    ['status' => 'Tidak', 'ket' => 'Perlu legalisir ijazah'],
                    ['status' => 'Ada', 'ket' => 'Verified HR'],
                    ['status' => 'Ada', 'ket' => 'Pengalaman Admin 2 Th'],
                    ['status' => 'Tidak', 'ket' => '-'],
                ]
            ],
            [
                'nama' => 'Budi Santoso',
                'jabatan' => 3, // Programmer
                'bidang' => 2, // Persandian
                'tahun' => 2026,
                'tgl' => '2026-04-14',
                'kesimpulan' => 'Administrasi Lengkap',
                'catatan' => 'Portofolio aplikasi dan sertifikasi keamanan informasi terverifikasi baik.',
                'items' => [
                    ['status' => 'Ada', 'ket' => 'Lengkap'],
                    ['status' => 'Ada', 'ket' => 'S1 Teknik Informatika'],
                    ['status' => 'Ada', 'ket' => 'Referensi atasan lama valid'],
                    ['status' => 'Ada', 'ket' => 'Pengalaman Fullstack 3 Th'],
                    ['status' => 'Ada', 'ket' => 'Sertifikat BNSP'],
                ]
            ],
            [
                'nama' => 'Rina Anggraini',
                'jabatan' => 4, // Staff Jaringan
                'bidang' => 1, // Aptika
                'tahun' => 2026,
                'tgl' => '2026-04-12',
                'kesimpulan' => 'Administrasi Lengkap',
                'catatan' => 'Semua berkas administrasi dan sertifikasi CCNA lengkap.',
                'items' => [
                    ['status' => 'Ada', 'ket' => 'Lengkap'],
                    ['status' => 'Ada', 'ket' => 'D4 Jaringan Komputer'],
                    ['status' => 'Ada', 'ket' => 'Verified'],
                    ['status' => 'Ada', 'ket' => 'Pengalaman 2 Th'],
                    ['status' => 'Tidak', 'ket' => '-'],
                ]
            ],
            [
                'nama' => 'Dedi Kurniawan',
                'jabatan' => 5, // Teknisi
                'bidang' => 2, // Persandian
                'tahun' => 2026,
                'tgl' => '2026-04-10',
                'kesimpulan' => 'Perlu melengkapi administrasi',
                'catatan' => 'SKCK dan surat bebas narkoba belum disertakan.',
                'items' => [
                    ['status' => 'Ada', 'ket' => 'Lengkap'],
                    ['status' => 'Ada', 'ket' => 'SMK TKJ'],
                    ['status' => 'Ada', 'ket' => 'Verified'],
                    ['status' => 'Ada', 'ket' => 'Pengalaman Teknisi 1 Th'],
                    ['status' => 'Tidak', 'ket' => 'Menunggu SKCK fisik'],
                ]
            ],
            [
                'nama' => 'Fajar Nugraha',
                'jabatan' => 1, // Staff IT
                'bidang' => 3, // IKP
                'tahun' => 2026,
                'tgl' => '2026-04-09',
                'kesimpulan' => 'Administrasi Lengkap',
                'catatan' => 'Kandidat telah melengkapi dokumen sesuai SOP.',
                'items' => [
                    ['status' => 'Ada', 'ket' => 'Lengkap'],
                    ['status' => 'Ada', 'ket' => 'S1 Sistem Informasi'],
                    ['status' => 'Ada', 'ket' => 'Verified via email'],
                    ['status' => 'Ada', 'ket' => 'IT Support 2 tahun'],
                    ['status' => 'Tidak', 'ket' => '-'],
                ]
            ],
            [
                'nama' => 'Dewi Lestari',
                'jabatan' => 2, // Admin
                'bidang' => 4, // E-Gov
                'tahun' => 2026,
                'tgl' => '2026-04-08',
                'kesimpulan' => 'Administrasi Lengkap',
                'catatan' => 'Administrasi lengkap, siap penugasan.',
                'items' => [
                    ['status' => 'Ada', 'ket' => 'Lengkap'],
                    ['status' => 'Ada', 'ket' => 'S1 Manajemen'],
                    ['status' => 'Ada', 'ket' => 'Verified'],
                    ['status' => 'Ada', 'ket' => 'Admin Kepegawaian'],
                    ['status' => 'Tidak', 'ket' => '-'],
                ]
            ],
            [
                'nama' => 'Hendro Prasetyo',
                'jabatan' => 6, // Analis Sistem
                'bidang' => 1, // Aptika
                'tahun' => 2026,
                'tgl' => '2026-04-07',
                'kesimpulan' => 'Administrasi Lengkap',
                'catatan' => 'Pengalaman kerja dan referensi terbukti kredibel.',
                'items' => [
                    ['status' => 'Ada', 'ket' => 'Lengkap'],
                    ['status' => 'Ada', 'ket' => 'S1 Informatika'],
                    ['status' => 'Ada', 'ket' => 'Verified'],
                    ['status' => 'Ada', 'ket' => 'System Analyst 4 tahun'],
                    ['status' => 'Ada', 'ket' => 'Sertifikat Scrum Master'],
                ]
            ],
            [
                'nama' => 'Maya Indah',
                'jabatan' => 3, // Programmer
                'bidang' => 1, // Aptika
                'tahun' => 2026,
                'tgl' => '2026-04-05',
                'kesimpulan' => 'Administrasi Lengkap',
                'catatan' => 'Semua berkas valid.',
                'items' => [
                    ['status' => 'Ada', 'ket' => 'Lengkap'],
                    ['status' => 'Ada', 'ket' => 'S1 Ilmu Komputer'],
                    ['status' => 'Ada', 'ket' => 'Verified'],
                    ['status' => 'Ada', 'ket' => 'Frontend Developer 2 th'],
                    ['status' => 'Tidak', 'ket' => '-'],
                ]
            ],
            [
                'nama' => 'Bambang Tri',
                'jabatan' => 4, // Staff Jaringan
                'bidang' => 2, // Persandian
                'tahun' => 2026,
                'tgl' => '2026-04-03',
                'kesimpulan' => 'Perlu melengkapi administrasi',
                'catatan' => 'Surat referensi kerja dari perusahaan sebelumnya belum ditandatangani.',
                'items' => [
                    ['status' => 'Ada', 'ket' => 'Lengkap'],
                    ['status' => 'Ada', 'ket' => 'D3 Teknik Komputer'],
                    ['status' => 'Tidak', 'ket' => 'Tanda tangan referensi belum ada'],
                    ['status' => 'Ada', 'ket' => 'Network Engineer 2 th'],
                    ['status' => 'Tidak', 'ket' => '-'],
                ]
            ],
            [
                'nama' => 'Ratna Sari',
                'jabatan' => 2, // Admin
                'bidang' => 5, // Statistik
                'tahun' => 2026,
                'tgl' => '2026-04-02',
                'kesimpulan' => 'Administrasi Lengkap',
                'catatan' => 'Kualifikasi sangat sesuai.',
                'items' => [
                    ['status' => 'Ada', 'ket' => 'Lengkap'],
                    ['status' => 'Ada', 'ket' => 'S1 Statistika'],
                    ['status' => 'Ada', 'ket' => 'Verified'],
                    ['status' => 'Ada', 'ket' => 'Data Entry & Admin'],
                    ['status' => 'Tidak', 'ket' => '-'],
                ]
            ],
            [
                'nama' => 'Agus Setiawan',
                'jabatan' => 5, // Teknisi
                'bidang' => 1, // Aptika
                'tahun' => 2026,
                'tgl' => '2026-03-30',
                'kesimpulan' => 'Administrasi Lengkap',
                'catatan' => 'Dokumen fisik diverifikasi lengkap.',
                'items' => [
                    ['status' => 'Ada', 'ket' => 'Lengkap'],
                    ['status' => 'Ada', 'ket' => 'D3 Elektronika'],
                    ['status' => 'Ada', 'ket' => 'Verified'],
                    ['status' => 'Ada', 'ket' => 'Hardware Maintenance'],
                    ['status' => 'Tidak', 'ket' => '-'],
                ]
            ],
            [
                'nama' => 'Eka Rahmawati',
                'jabatan' => 1, // Staff IT
                'bidang' => 4, // E-Gov
                'tahun' => 2026,
                'tgl' => '2026-03-28',
                'kesimpulan' => 'Administrasi Lengkap',
                'catatan' => 'Sesuai kriteria penerimaan tenaga alih daya.',
                'items' => [
                    ['status' => 'Ada', 'ket' => 'Lengkap'],
                    ['status' => 'Ada', 'ket' => 'S1 Sistem Informasi'],
                    ['status' => 'Ada', 'ket' => 'Verified'],
                    ['status' => 'Ada', 'ket' => 'Technical Support'],
                    ['status' => 'Tidak', 'ket' => '-'],
                ]
            ],
            [
                'nama' => 'Yusuf Maulana',
                'jabatan' => 3, // Programmer
                'bidang' => 1, // Aptika
                'tahun' => 2026,
                'tgl' => '2026-03-25',
                'kesimpulan' => 'Administrasi Lengkap',
                'catatan' => 'Kemampuan teknis dan administrasi memenuhi standar.',
                'items' => [
                    ['status' => 'Ada', 'ket' => 'Lengkap'],
                    ['status' => 'Ada', 'ket' => 'S1 Informatika'],
                    ['status' => 'Ada', 'ket' => 'Verified'],
                    ['status' => 'Ada', 'ket' => 'Backend Laravel/Golang'],
                    ['status' => 'Ada', 'ket' => 'Sertifikasi Cloud Associate'],
                ]
            ],
            [
                'nama' => 'Indra Wijaya',
                'jabatan' => 4, // Staff Jaringan
                'bidang' => 3, // IKP
                'tahun' => 2026,
                'tgl' => '2026-03-22',
                'kesimpulan' => 'Perlu melengkapi administrasi',
                'catatan' => 'Foto copy sertifikat kompetensi belum dilegalisir.',
                'items' => [
                    ['status' => 'Ada', 'ket' => 'Lengkap'],
                    ['status' => 'Ada', 'ket' => 'SMK Komputer Jaringan'],
                    ['status' => 'Ada', 'ket' => 'Verified'],
                    ['status' => 'Ada', 'ket' => 'Instalasi LAN/WLAN'],
                    ['status' => 'Tidak', 'ket' => 'Sertifikat belum legalisir'],
                ]
            ],
            [
                'nama' => 'Nia Safitri',
                'jabatan' => 2, // Admin
                'bidang' => 6, // Sekretariat
                'tahun' => 2026,
                'tgl' => '2026-03-20',
                'kesimpulan' => 'Administrasi Lengkap',
                'catatan' => 'Kelengkapan dokumen memenuhi syarat.',
                'items' => [
                    ['status' => 'Ada', 'ket' => 'Lengkap'],
                    ['status' => 'Ada', 'ket' => 'D3 Kesekretariatan'],
                    ['status' => 'Ada', 'ket' => 'Verified'],
                    ['status' => 'Ada', 'ket' => 'Staff Administrasi 3 th'],
                    ['status' => 'Tidak', 'ket' => '-'],
                ]
            ],
            [
                'nama' => 'Rizky Ramadhan',
                'jabatan' => 1, // Staff IT
                'bidang' => 1, // Aptika
                'tahun' => 2026,
                'tgl' => '2026-03-18',
                'kesimpulan' => 'Administrasi Lengkap',
                'catatan' => 'Dokumen fisik lengkap dan sah.',
                'items' => [
                    ['status' => 'Ada', 'ket' => 'Lengkap'],
                    ['status' => 'Ada', 'ket' => 'S1 Teknologi Informasi'],
                    ['status' => 'Ada', 'ket' => 'Verified'],
                    ['status' => 'Ada', 'ket' => 'IT Helpdesk'],
                    ['status' => 'Tidak', 'ket' => '-'],
                ]
            ],
            [
                'nama' => 'Tri Wahyuni',
                'jabatan' => 5, // Teknisi
                'bidang' => 2, // Persandian
                'tahun' => 2026,
                'tgl' => '2026-03-15',
                'kesimpulan' => 'Administrasi Lengkap',
                'catatan' => 'Hasil verifikasi riwayat kerja memuaskan.',
                'items' => [
                    ['status' => 'Ada', 'ket' => 'Lengkap'],
                    ['status' => 'Ada', 'ket' => 'D3 Teknik Listrik'],
                    ['status' => 'Ada', 'ket' => 'Verified'],
                    ['status' => 'Ada', 'ket' => 'Teknisi Perangkat'],
                    ['status' => 'Tidak', 'ket' => '-'],
                ]
            ],
            [
                'nama' => 'Hadi Sucipto',
                'jabatan' => 3, // Programmer
                'bidang' => 4, // E-Gov
                'tahun' => 2026,
                'tgl' => '2026-03-12',
                'kesimpulan' => 'Administrasi Lengkap',
                'catatan' => 'Semua kriteria verifikasi lolos.',
                'items' => [
                    ['status' => 'Ada', 'ket' => 'Lengkap'],
                    ['status' => 'Ada', 'ket' => 'S1 Sistem Komputer'],
                    ['status' => 'Ada', 'ket' => 'Verified'],
                    ['status' => 'Ada', 'ket' => 'Mobile App Developer'],
                    ['status' => 'Tidak', 'ket' => '-'],
                ]
            ],
            [
                'nama' => 'Lia Kusuma',
                'jabatan' => 2, // Admin
                'bidang' => 1, // Aptika
                'tahun' => 2026,
                'tgl' => '2026-03-10',
                'kesimpulan' => 'Perlu melengkapi administrasi',
                'catatan' => 'NPWP belum aktif, diminta konfirmasi ke KPP.',
                'items' => [
                    ['status' => 'Tidak', 'ket' => 'NPWP status non-aktif'],
                    ['status' => 'Ada', 'ket' => 'S1 Ekonomi'],
                    ['status' => 'Ada', 'ket' => 'Verified'],
                    ['status' => 'Ada', 'ket' => 'Admin Keuangan'],
                    ['status' => 'Tidak', 'ket' => '-'],
                ]
            ],
            [
                'nama' => 'Ahmad Fauzi',
                'jabatan' => 6, // Analis Sistem
                'bidang' => 1, // Aptika
                'tahun' => 2026,
                'tgl' => '2026-03-08',
                'kesimpulan' => 'Administrasi Lengkap',
                'catatan' => 'Portofolio analisis arsitektur aplikasi terkonfirmasi.',
                'items' => [
                    ['status' => 'Ada', 'ket' => 'Lengkap'],
                    ['status' => 'Ada', 'ket' => 'S2 Sistem Informasi'],
                    ['status' => 'Ada', 'ket' => 'Verified'],
                    ['status' => 'Ada', 'ket' => 'Lead Analyst 5 tahun'],
                    ['status' => 'Ada', 'ket' => 'Sertifikat TOGAF 9'],
                ]
            ],
            [
                'nama' => 'Putri Wulandari',
                'jabatan' => 1, // Staff IT
                'bidang' => 7, // PLDDIG
                'tahun' => 2026,
                'tgl' => '2026-03-05',
                'kesimpulan' => 'Administrasi Lengkap',
                'catatan' => 'Semua dokumen telah dicek fisik.',
                'items' => [
                    ['status' => 'Ada', 'ket' => 'Lengkap'],
                    ['status' => 'Ada', 'ket' => 'S1 Teknik Informatika'],
                    ['status' => 'Ada', 'ket' => 'Verified'],
                    ['status' => 'Ada', 'ket' => 'IT Operations'],
                    ['status' => 'Tidak', 'ket' => '-'],
                ]
            ],
            [
                'nama' => 'Dimas Anggoro',
                'jabatan' => 4, // Staff Jaringan
                'bidang' => 1, // Aptika
                'tahun' => 2026,
                'tgl' => '2026-03-02',
                'kesimpulan' => 'Administrasi Lengkap',
                'catatan' => 'Hasil verifikasi latar belakang sesuai data.',
                'items' => [
                    ['status' => 'Ada', 'ket' => 'Lengkap'],
                    ['status' => 'Ada', 'ket' => 'D4 Telekomunikasi'],
                    ['status' => 'Ada', 'ket' => 'Verified'],
                    ['status' => 'Ada', 'ket' => 'Network Admin'],
                    ['status' => 'Tidak', 'ket' => '-'],
                ]
            ],
            [
                'nama' => 'Citra Kirana',
                'jabatan' => 2, // Admin
                'bidang' => 1, // Aptika
                'tahun' => 2026,
                'tgl' => '2026-02-28',
                'kesimpulan' => 'Perlu melengkapi administrasi',
                'catatan' => 'Pas foto dan surat keterangan sehat belum dilampirkan.',
                'items' => [
                    ['status' => 'Ada', 'ket' => 'KTP & KK'],
                    ['status' => 'Ada', 'ket' => 'S1 Administrasi Bisnis'],
                    ['status' => 'Ada', 'ket' => 'Verified'],
                    ['status' => 'Ada', 'ket' => 'Admin Umum 1 th'],
                    ['status' => 'Tidak', 'ket' => 'Surat sehat & foto menyusul'],
                ]
            ],
        ];

        foreach ($initialData as $data) {
            $screeningId = DB::table('screening_karyawan')->insertGetId([
                'nama_karyawan'     => $data['nama'],
                'id_jabatan'        => $data['jabatan'],
                'id_bidang'         => $data['bidang'],
                'tahun'             => $data['tahun'],
                'tanggal_screening' => $data['tgl'],
                'kesimpulan'        => $data['kesimpulan'],
                'catatan'           => $data['catatan'],
                'created_at'        => now(),
                'updated_at'        => now(),
            ]);

            $firstDetailId = null;
            foreach ($data['items'] as $itemIdx => $it) {
                $detailId = DB::table('detail_screening')->insertGetId([
                    'id_screening' => $screeningId,
                    'id_item'      => $itemIdx + 1,
                    'status'       => $it['status'],
                    'keterangan'   => $it['ket'],
                    'kesimpulan'   => $data['kesimpulan'],
                    'catatan'      => $data['catatan'],
                    'created_at'   => now(),
                    'updated_at'   => now(),
                ]);
                if ($firstDetailId === null) {
                    $firstDetailId = $detailId;
                }
            }

            // Hubungkan id_detail pada tabel screening_karyawan sesuai ERD
            if ($firstDetailId) {
                DB::table('screening_karyawan')
                    ->where('id_screening', $screeningId)
                    ->update(['id_detail' => $firstDetailId]);
            }
        }
    }
}
