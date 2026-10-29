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

class SmkiLaporanAuditTahunanController extends Controller
{
    private const WITH = ['timAuditor', 'objekAudit.klausul', 'objekAudit.jadwal'];

    private function isAdmin(Request $request): bool
    {
        $user = $request->user();
        return $user && ($user->role === 'admin' || str_contains(strtolower($user->email ?? ''), 'admin'));
    }

    public function index()
    {
        $items = SmkiLaporanAuditTahunan::with(self::WITH)
            ->orderBy('tahun_smki', 'desc')
            ->orderBy('id', 'desc')
            ->get();

        return response()->json(['success' => true, 'status' => 'success', 'data' => $items]);
    }

    public function show($id)
    {
        $item = SmkiLaporanAuditTahunan::with(self::WITH)->find($id);
        if (!$item) {
            return response()->json(['success' => false, 'message' => 'Formulir tidak ditemukan.'], 404);
        }
        return response()->json(['success' => true, 'status' => 'success', 'data' => $item]);
    }

    public function lookup()
    {
        $auditors = Auditor::select('id_auditor', 'nama_auditor', 'nip_auditor')->orderBy('nama_auditor')->get();
        $bidang = BidangAuditee::select('id_bidang_auditee', 'bidang_auditee')->orderBy('bidang_auditee')->get();

        return response()->json([
            'success' => true,
            'data' => [
                'auditors' => $auditors,
                'bidang_auditee' => $bidang,
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

    public function exportDocx($id, SmkiLaporanAuditTahunanDocxService $service)
    {
        try {
            $item = SmkiLaporanAuditTahunan::with(self::WITH)->findOrFail($id);
            $path = $service->generate($item);

            return response()->download($path, 'FR-008_Laporan_Audit_Tahunan_' . $item->tahun_smki . '.docx', [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ])->deleteFileAfterSend(true);
        } catch (\Throwable $e) {
            Log::error('Error ekspor FR-008: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Gagal mengekspor dokumen: ' . $e->getMessage()], 500);
        }
    }
}

