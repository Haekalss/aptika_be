<?php

namespace App\Http\Controllers\Smki;

use App\Http\Controllers\Controller;
use App\Models\Auditor;
use App\Models\BidangAuditee;
use App\Models\SmkiLaporanAuditTahunan;
use App\Services\SmkiLaporanAuditTahunanDocxService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class SmkiLaporanAuditTahunanController extends Controller
{
    private const WITH = ['timAuditor', 'objekAudit.klausul', 'objekAudit.jadwal'];

    private function ensureTablesExist(): void
    {
        if (!Schema::hasTable('smki_laporan_audit_tahunans')) {
            try {
                $migrationFile = database_path('migrations/2026_10_29_000001_create_smki_laporan_audit_tahunan_tables.php');
                if (file_exists($migrationFile)) {
                    $migration = require $migrationFile;
                    $migration->up();
                }
            } catch (\Throwable $e) {
                Log::warning('Auto-migration FR-008 gagal: ' . $e->getMessage());
            }
        }
    }

    private function isAdmin(Request $request): bool
    {
        $user = $request->user();
        return $user && ($user->role === 'admin' || str_contains(strtolower($user->email ?? ''), 'admin'));
    }

    public function index()
    {
        try {
            $this->ensureTablesExist();

            if (!Schema::hasTable('smki_laporan_audit_tahunans')) {
                return response()->json(['success' => true, 'status' => 'success', 'data' => []]);
            }

            $items = SmkiLaporanAuditTahunan::with(self::WITH)
                ->orderBy('tahun_smki', 'desc')
                ->orderBy('id', 'desc')
                ->get();

            return response()->json(['success' => true, 'status' => 'success', 'data' => $items]);
        } catch (\Throwable $e) {
            Log::error('Error getLaporanAuditTahunanList: ' . $e->getMessage());
            return response()->json(['success' => true, 'status' => 'success', 'data' => []]);
        }
    }

    public function show($id)
    {
        try {
            $this->ensureTablesExist();

            if (!Schema::hasTable('smki_laporan_audit_tahunans')) {
                return response()->json(['success' => false, 'message' => 'Tabel belum tersedia.'], 404);
            }

            $item = SmkiLaporanAuditTahunan::with(self::WITH)->find($id);
            if (!$item) {
                return response()->json(['success' => false, 'message' => 'Formulir tidak ditemukan.'], 404);
            }
            return response()->json(['success' => true, 'status' => 'success', 'data' => $item]);
        } catch (\Throwable $e) {
            Log::error('Error getLaporanAuditTahunanById: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Gagal mengambil data formulir.'], 500);
        }
    }

    public function lookup()
    {
        try {
            $auditors = [];
            $bidang = [];

            if (Schema::hasTable('auditor')) {
                $auditors = Auditor::select('id_auditor', 'nama_auditor', 'nip_auditor')->orderBy('nama_auditor')->get();
            }
            if (empty($auditors) || (is_object($auditors) && $auditors->isEmpty())) {
                $defaultAuditors = [
                    ['nama_auditor' => 'Dr. Budi Santoso', 'nip_auditor' => '197501011998021001'],
                    ['nama_auditor' => 'Siti Nurhaliza', 'nip_auditor' => '198503152010012002'],
                    ['nama_auditor' => 'Ahmad Wijaya', 'nip_auditor' => '198811202015011003'],
                    ['nama_auditor' => 'Eka Putri', 'nip_auditor' => '199205122018022001'],
                    ['nama_auditor' => 'Rudi Hartono', 'nip_auditor' => '198701201992031001'],
                ];
                if (Schema::hasTable('auditor')) {
                    foreach ($defaultAuditors as $da) {
                        Auditor::firstOrCreate(['nama_auditor' => $da['nama_auditor']], $da);
                    }
                    $auditors = Auditor::select('id_auditor', 'nama_auditor', 'nip_auditor')->orderBy('nama_auditor')->get();
                } else {
                    $auditors = collect($defaultAuditors)->map(fn($d, $i) => array_merge(['id_auditor' => $i + 1], $d));
                }
            }

            if (Schema::hasTable('bidang_auditee')) {
                $bidang = BidangAuditee::select('id_bidang_auditee', 'bidang_auditee')->orderBy('bidang_auditee')->get();
            }
            if (empty($bidang) || (is_object($bidang) && $bidang->isEmpty())) {
                $defaultBidang = [
                    ['bidang_auditee' => 'Sekretariat'],
                    ['bidang_auditee' => 'Bidang E-Government'],
                    ['bidang_auditee' => 'Bidang Aplikasi Informatika'],
                    ['bidang_auditee' => 'Bidang Informasi dan Komunikasi Publik'],
                    ['bidang_auditee' => 'Bidang Persandian dan Keamanan Informasi'],
                    ['bidang_auditee' => 'Bidang Statistik'],
                    ['bidang_auditee' => 'UPTD Pusat Layanan Digital'],
                ];
                if (Schema::hasTable('bidang_auditee')) {
                    foreach ($defaultBidang as $db) {
                        BidangAuditee::firstOrCreate(['bidang_auditee' => $db['bidang_auditee']], $db);
                    }
                    $bidang = BidangAuditee::select('id_bidang_auditee', 'bidang_auditee')->orderBy('bidang_auditee')->get();
                } else {
                    $bidang = collect($defaultBidang)->map(fn($d, $i) => array_merge(['id_bidang_auditee' => $i + 1], $d));
                }
            }

            $pegawai = [];
            if (Schema::hasTable('pegawai')) {
                $pegawai = \App\Models\Pegawai::select('id', 'nama', 'nip', 'jabatan', 'role')->orderBy('nama')->get();
            }
            if (empty($pegawai) || (is_object($pegawai) && $pegawai->isEmpty())) {
                $defaultPegawai = [
                    ['id' => 1, 'nama' => 'Ahmad Fauzi, S.T., M.Kom.', 'nip' => '198001012005011001', 'jabatan' => 'Kepala Bidang Aplikasi Informatika', 'role' => 'Kepala Bidang'],
                    ['id' => 2, 'nama' => 'Budi Santoso, S.Kom.', 'nip' => '198502022010011002', 'jabatan' => 'Pengelola SMKI / Sandiman', 'role' => 'Pegawai'],
                    ['id' => 3, 'nama' => 'Irna Lestari, S.Si.', 'nip' => '198703032011012003', 'jabatan' => 'Pranata Komputer Ahli Muda', 'role' => 'Pegawai'],
                    ['id' => 4, 'nama' => 'Rian Hidayat, M.T.', 'nip' => '199004042015011004', 'jabatan' => 'Auditor TI Internal', 'role' => 'Pegawai'],
                ];
                $pegawai = collect($defaultPegawai);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'auditors' => $auditors,
                    'bidang_auditee' => $bidang,
                    'pegawai' => $pegawai,
                    'standard_clauses' => [
                        'Klausul 4 (Konteks Organisasi)',
                        'Klausul 5 (Kepemimpinan)',
                        'Klausul 6 (Perencanaan)',
                        'Klausul 7 (Dukungan)',
                        'Klausul 8 (Operasional)',
                        'Klausul 9 (Evaluasi Kinerja)',
                        'Klausul 10 (Peningkatan)',
                        'Annex A.5 (Pengendalian Organisasi)',
                        'Annex A.6 (Pengendalian Orang)',
                        'Annex A.7 (Pengendalian Fisik)',
                        'Annex A.8 (Pengendalian Teknologi)',
                    ],
                    'default_meta' => [
                        'no_dokumen' => 'FR-008/KOM.03.05/SANDIKAMI',
                        'no_revisi' => '1.0',
                        'tanggal_berlaku' => now()->format('Y-m-d'),
                    ],
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('Error lookup FR-008: ' . $e->getMessage());
            return response()->json([
                'success' => true,
                'data' => [
                    'auditors' => [],
                    'bidang_auditee' => [],
                    'pegawai' => [],
                    'standard_clauses' => [],
                    'default_meta' => [
                        'no_dokumen' => 'FR-008/KOM.03.05/SANDIKAMI',
                        'no_revisi' => '1.0',
                        'tanggal_berlaku' => now()->format('Y-m-d'),
                    ],
                ],
            ]);
        }
    }

    /**
     * Simpan (buat / ubah bila "id" ada) formulir beserta seluruh anak datanya.
     */
    public function storeAll(Request $request)
    {
        // Semua user terautentikasi boleh menambah formulir baru; mengubah formulir existing hanya Administrator.
        if ($request->filled('id') && !$this->isAdmin($request)) {
            return response()->json(['success' => false, 'message' => 'Hanya Administrator yang dapat mengubah formulir tahunan audit.'], 403);
        }

        $data = $request->validate([
            'id' => 'nullable|integer',
            'tahun_smki' => 'required|integer|min:2000|max:2100',
            'no_dokumen' => 'required|string|max:100',
            'no_revisi' => 'required|string|max:50',
            'tanggal_berlaku' => 'required|date',
            'kriteria_audit' => 'nullable|string',
            'tujuan' => 'nullable|string',
            'disusun_oleh' => 'nullable|string|max:255',
            'jabatan_penyusun' => 'nullable|string|max:255',
            'disahkan_oleh' => 'nullable|string|max:255',
            'jabatan_pengesah' => 'nullable|string|max:255',
            'status' => 'nullable|string|in:Draft,Selesai',
            'tim_auditor' => 'nullable|array',
            'tim_auditor.*.nama_auditor' => 'required|string|max:255',
            'tim_auditor.*.unit_kerja' => 'nullable|string|max:255',
            'tim_auditor.*.peran' => 'nullable|string|max:50',
            'objek_audit' => 'nullable|array',
            'objek_audit.*.kode_objek' => 'nullable|string|max:50',
            'objek_audit.*.objek_audit' => 'required|string|max:255',
            'objek_audit.*.ruang_lingkup' => 'nullable|string',
            'objek_audit.*.unit_kerja' => 'nullable|string|max:255',
            'objek_audit.*.klausul' => 'nullable|array',
            'objek_audit.*.jadwal' => 'nullable|array',
        ]);

        $this->ensureTablesExist();

        DB::beginTransaction();
        try {
            $payload = collect($data)->only([
                'tahun_smki', 'no_dokumen', 'no_revisi', 'tanggal_berlaku', 'kriteria_audit', 'tujuan',
                'disusun_oleh', 'jabatan_penyusun', 'disahkan_oleh', 'jabatan_pengesah',
            ])->all();
            $payload['status'] = $data['status'] ?? 'Draft';

            if (!empty($data['id'])) {
                $laporan = SmkiLaporanAuditTahunan::findOrFail($data['id']);
                $laporan->update($payload);
                $laporan->timAuditor()->delete();
                $laporan->objekAudit()->delete(); // klausul & jadwal ikut terhapus (cascade)
            } else {
                $payload['created_by'] = $request->user()?->id;
                $laporan = SmkiLaporanAuditTahunan::create($payload);
            }

            foreach ($data['tim_auditor'] ?? [] as $t) {
                $laporan->timAuditor()->create([
                    'nama_auditor' => $t['nama_auditor'],
                    'unit_kerja' => $t['unit_kerja'] ?? null,
                    'peran' => $t['peran'] ?? 'Auditor',
                ]);
            }

            foreach ($data['objek_audit'] ?? [] as $o) {
                $objek = $laporan->objekAudit()->create([
                    'kode_objek' => $o['kode_objek'] ?? null,
                    'objek_audit' => $o['objek_audit'],
                    'ruang_lingkup' => $o['ruang_lingkup'] ?? null,
                    'unit_kerja' => $o['unit_kerja'] ?? null,
                ]);
                foreach ($o['klausul'] ?? [] as $k) {
                    $name = is_array($k) ? ($k['klausul_annex'] ?? null) : $k;
                    if ($name) {
                        $objek->klausul()->create([
                            'klausul_annex' => $name,
                            'uraian_klausul' => is_array($k) ? ($k['uraian_klausul'] ?? null) : null,
                        ]);
                    }
                }
                foreach ($o['jadwal'] ?? [] as $j) {
                    $bulan = (int) (is_array($j) ? ($j['bulan'] ?? 0) : $j);
                    if ($bulan >= 1 && $bulan <= 12) {
                        $objek->jadwal()->create(['bulan' => $bulan]);
                    }
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'status' => 'success',
                'message' => 'Formulir tahunan audit berhasil disimpan.',
                'data' => $laporan->load(self::WITH),
            ], empty($data['id']) ? 201 : 200);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Error menyimpan FR-008: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Gagal menyimpan formulir: ' . $e->getMessage()], 500);
        }
    }

    public function destroy(Request $request, $id)
    {
        if (!$this->isAdmin($request)) {
            return response()->json(['success' => false, 'message' => 'Hanya Administrator yang dapat menghapus formulir.'], 403);
        }
        $item = SmkiLaporanAuditTahunan::find($id);
        if (!$item) {
            return response()->json(['success' => false, 'message' => 'Formulir tidak ditemukan.'], 404);
        }
        $item->delete();
        return response()->json(['success' => true, 'status' => 'success', 'message' => 'Formulir berhasil dihapus.']);
    }

    public function exportDocx(Request $request, $id, SmkiLaporanAuditTahunanDocxService $service)
    {
        try {
            $item = SmkiLaporanAuditTahunan::with(self::WITH)->findOrFail($id);
            $overrides = [
                'disahkan_oleh' => $request->query('disahkan_oleh'),
                'disahkan_nip' => $request->query('disahkan_nip'),
                'disusun_oleh' => $request->query('disusun_oleh'),
                'disusun_nip' => $request->query('disusun_nip'),
            ];
            $path = $service->generate($item, $overrides);

            return response()->download($path, 'FR-008_Laporan_Audit_Tahunan_' . $item->tahun_smki . '.docx', [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ])->deleteFileAfterSend(true);
        } catch (\Throwable $e) {
            Log::error('Error ekspor FR-008: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Gagal mengekspor dokumen: ' . $e->getMessage()], 500);
        }
    }
}

