<?php

namespace App\Http\Controllers\Smki;

use App\Http\Controllers\Controller;
use App\Models\DataCenterAsset;
use App\Models\LaporanKerusakanDataCenter;
use App\Models\LaporanKerusakanHistory;
use App\Models\Pegawai;
use App\Models\DaftarAsetTi;
use App\Models\User;
use App\Services\SmkiLaporanKerusakanDocxService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SmkiLaporanKerusakanController extends Controller
{
    protected SmkiLaporanKerusakanDocxService $docxService;

    public function __construct(SmkiLaporanKerusakanDocxService $docxService)
    {
        $this->docxService = $docxService;
    }

    /**
     * Display a listing of damage reports with filters and stats.
     */
    public function index(Request $request)
    {
        try {
            $query = LaporanKerusakanDataCenter::with(['asset', 'pelapor', 'penanggungJawab', 'supervisor'])
                ->orderBy('created_at', 'desc');

            // Filter status
            if ($request->filled('status') && $request->status !== 'all') {
                $query->where('status', $request->status);
            }

            // Filter keyword (nama aset, kode, penanggung jawab, pelapor)
            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('nama_aset', 'like', "%{$search}%")
                        ->orWhere('kode_aset', 'like', "%{$search}%")
                        ->orWhere('penanggung_jawab_nama', 'like', "%{$search}%")
                        ->orWhere('pelapor_nama', 'like', "%{$search}%")
                        ->orWhere('nomor_laporan', 'like', "%{$search}%");
                });
            }

            // Filter tanggal
            if ($request->filled('tanggal')) {
                $query->whereDate('tanggal', $request->tanggal);
            }

            $perPage = $request->input('per_page', 10);
            $reports = $query->paginate($perPage);

            // Statistik Ringkasan untuk StatCard
            $totalReports = LaporanKerusakanDataCenter::count();
            $baruCount = LaporanKerusakanDataCenter::where('status', 'Baru')->count();
            $diprosesCount = LaporanKerusakanDataCenter::where('status', 'Diproses')->count();
            $selesaiCount = LaporanKerusakanDataCenter::whereIn('status', ['Selesai', 'Closed'])->count();

            return response()->json([
                'status' => 'success',
                'data' => $reports->items(),
                'pagination' => [
                    'current_page' => $reports->currentPage(),
                    'last_page' => $reports->lastPage(),
                    'per_page' => $reports->perPage(),
                    'total' => $reports->total(),
                ],
                'stats' => [
                    'total' => $totalReports,
                    'baru' => $baruCount,
                    'diproses' => $diprosesCount,
                    'selesai' => $selesaiCount,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching SMKI damage reports: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Gagal mengambil daftar laporan kerusakan: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get lookup master data (assets, technicians/users, master pegawai, master penanggung jawab).
     */
    public function lookup()
    {
        try {
            $assets = DataCenterAsset::orderBy('nama_aset', 'asc')->get();
            $users = User::select('id', 'name', 'email', 'role')->orderBy('name', 'asc')->get();
            
            // Daftar Pejabat / Supervisor yang berwenang mengetahui laporan
            $supervisors = User::where(function ($q) {
                $q->where('role', 'admin')
                    ->orWhere('role', 'supervisor')
                    ->orWhere('email', 'like', '%admin%');
            })->select('id', 'name', 'email', 'role')->orderBy('name', 'asc')->get();

            if ($supervisors->isEmpty()) {
                $supervisors = $users;
            }

            // Integrasi Data Master Pegawai
            $pegawais = Pegawai::select('id', 'nama', 'nip', 'jabatan')->orderBy('nama', 'asc')->get();

            // Integrasi Data Master Penanggung Jawab Aset TI
            $masterPjs = DB::table('aset_ti_penanggung_jawabs')->select('id', 'nama_pj')->orderBy('nama_pj', 'asc')->get();

            // Integrasi Daftar Aset TI (Aset TI APTIKA)
            $tiAssets = DaftarAsetTi::select('id', 'nama_aset', 'kode', 'lokasi', 'no_seri')
                ->orderBy('nama_aset', 'asc')
                ->get();

            return response()->json([
                'status' => 'success',
                'data' => [
                    'assets' => $assets,
                    'users' => $users,
                    'supervisors' => $supervisors,
                    'pegawais' => $pegawais,
                    'master_pjs' => $masterPjs,
                    'ti_assets' => $tiAssets,
                    'statuses' => ['Baru', 'Diproses', 'Selesai', 'Closed'],
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Gagal memuat lookup data SMKI: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Store a new damage report.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'tanggal' => 'required|date',
            'jam' => 'nullable|string',
            'aset_id' => 'required|exists:data_center_assets,id',
            'jenis_kerusakan' => 'required|string',
            'penyebab' => 'nullable|string',
            'uraian_tindakan' => 'nullable|string',
            'status' => 'nullable|string',
            'target_selesai' => 'nullable|date',
            'penanggung_jawab_id' => 'nullable|exists:users,id',
            'supervisor_id' => 'nullable|exists:users,id',
            'supervisor_nama' => 'nullable|string',
            'pelapor_nama' => 'nullable|string',
        ]);

        DB::beginTransaction();
        try {
            $asset = DataCenterAsset::findOrFail($validated['aset_id']);
            $user = $request->user();
            $isAdmin = $user && ($user->role === 'admin' || str_contains(strtolower($user->email ?? ''), 'admin'));

            // Generate nomor laporan otomatis (Format standar dokumen SMKI)
            $yearMonth = date('Ym');
            $latest = LaporanKerusakanDataCenter::where('nomor_laporan', 'like', "LKR-{$yearMonth}-%")
                ->orderBy('id', 'desc')
                ->first();
            $sequence = 1;
            if ($latest) {
                $parts = explode('-', $latest->nomor_laporan);
                if (isset($parts[2])) {
                    $sequence = intval($parts[2]) + 1;
                }
            }
            $nomorLaporan = sprintf('LKR-%s-%04d', $yearMonth, $sequence);

            // Penanggung Jawab Teknisi
            $pjUser = null;
            if (!empty($validated['penanggung_jawab_id'])) {
                $pjUser = User::find($validated['penanggung_jawab_id']);
            } elseif ($asset->penanggung_jawab_id) {
                $pjUser = User::find($asset->penanggung_jawab_id);
            }

            // Supervisor / Pejabat yang Mengetahui
            $spUser = null;
            if (!empty($validated['supervisor_id'])) {
                $spUser = User::find($validated['supervisor_id']);
            }
            $supervisorNama = $validated['supervisor_nama'] ?? ($spUser ? $spUser->name : null);

            // Hak Akses Status: Teknisi biasa HANYA bisa set sampai 'Selesai', status 'Closed' wajib via validasi Supervisor
            $status = $validated['status'] ?? 'Baru';
            if (!$isAdmin && $status === 'Closed') {
                $status = 'Selesai';
            }

            // Pelapor: User biasa otomatis menggunakan identitas akun sendiri
            $pelaporId = $user ? $user->id : null;
            $pelaporNama = $user ? $user->name : 'Teknisi Data Center';
            if ($isAdmin && !empty($validated['pelapor_nama'])) {
                $pelaporNama = $validated['pelapor_nama'];
            }

            $report = LaporanKerusakanDataCenter::create([
                'nomor_laporan' => $nomorLaporan,
                'tanggal' => $validated['tanggal'],
                'jam' => $validated['jam'] ?? date('H.i') . ' WIB',
                'aset_id' => $asset->id,
                'nama_aset' => $asset->nama_aset,
                'kode_aset' => $asset->kode_aset,
                'lokasi_rak' => $asset->lokasi_rak,
                'jenis_kerusakan' => $validated['jenis_kerusakan'],
                'penyebab' => $validated['penyebab'] ?? null,
                'uraian_tindakan' => $validated['uraian_tindakan'] ?? null,
                'target_selesai' => $validated['target_selesai'] ?? null,
                'pelapor_id' => $pelaporId,
                'pelapor_nama' => $pelaporNama,
                'penanggung_jawab_id' => $pjUser ? $pjUser->id : null,
                'penanggung_jawab_nama' => $pjUser ? $pjUser->name : ($asset->penanggung_jawab_default ?? null),
                'supervisor_id' => $spUser ? $spUser->id : ($validated['supervisor_id'] ?? null),
                'supervisor_nama' => $supervisorNama,
                'status' => $status,
            ]);

            // Update status operasional aset jika rusak
            if ($status !== 'Closed' && $status !== 'Selesai') {
                $asset->update(['status_operasional' => 'Maintenance']);
            }

            // Catat riwayat audit log SMKI
            LaporanKerusakanHistory::create([
                'laporan_id' => $report->id,
                'user_id' => $user ? $user->id : null,
                'action' => 'Pembuatan Laporan Kerusakan',
                'status_from' => null,
                'status_to' => $status,
                'catatan' => 'Laporan berhasil dibuat via Sistem SMKI Aptika.',
            ]);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Laporan kerusakan berhasil disimpan.',
                'data' => $report->load(['asset', 'pelapor', 'penanggungJawab', 'supervisor', 'histories']),
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error saving SMKI damage report: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Gagal menyimpan laporan kerusakan: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Display a specific damage report.
     */
    public function show($id)
    {
        try {
            $report = LaporanKerusakanDataCenter::with(['asset', 'pelapor', 'penanggungJawab', 'supervisor', 'histories.user'])
                ->findOrFail($id);

            return response()->json([
                'status' => 'success',
                'data' => $report,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Laporan tidak ditemukan.',
            ], 404);
        }
    }

    /**
     * Update an existing damage report.
     */
    public function update(Request $request, $id)
    {
        $report = LaporanKerusakanDataCenter::findOrFail($id);

        $validated = $request->validate([
            'tanggal' => 'sometimes|required|date',
            'jam' => 'nullable|string',
            'aset_id' => 'sometimes|required|exists:data_center_assets,id',
            'jenis_kerusakan' => 'sometimes|required|string',
            'penyebab' => 'nullable|string',
            'uraian_tindakan' => 'nullable|string',
            'status' => 'nullable|string',
            'target_selesai' => 'nullable|date',
            'penanggung_jawab_id' => 'nullable|exists:users,id',
            'supervisor_id' => 'nullable|exists:users,id',
            'supervisor_nama' => 'nullable|string',
        ]);

        DB::beginTransaction();
        try {
            $oldStatus = $report->status;
            $user = $request->user();
            $isAdmin = $user && ($user->role === 'admin' || str_contains(strtolower($user->email ?? ''), 'admin'));

            // Hak Akses Update: Jika status sudah Closed, teknisi biasa tidak boleh mengubah data lagi
            if (!$isAdmin && $report->status === 'Closed') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Laporan ini telah disahkan (Closed) oleh Supervisor. Hanya Administrator yang dapat mengubah data ini.',
                ], 403);
            }

            // User biasa tidak bisa langsung mengesahkan (Closed) tanpa approval supervisor
            if (!$isAdmin && isset($validated['status']) && $validated['status'] === 'Closed') {
                unset($validated['status']);
            }

            if (!empty($validated['aset_id']) && $validated['aset_id'] != $report->aset_id) {
                $asset = DataCenterAsset::find($validated['aset_id']);
                if ($asset) {
                    $report->nama_aset = $asset->nama_aset;
                    $report->kode_aset = $asset->kode_aset;
                    $report->lokasi_rak = $asset->lokasi_rak;
                }
            }

            if (isset($validated['penanggung_jawab_id'])) {
                $pjUser = User::find($validated['penanggung_jawab_id']);
                $report->penanggung_jawab_nama = $pjUser ? $pjUser->name : null;
            }

            if (isset($validated['supervisor_id'])) {
                $spUser = User::find($validated['supervisor_id']);
                $report->supervisor_id = $spUser ? $spUser->id : null;
                $report->supervisor_nama = $validated['supervisor_nama'] ?? ($spUser ? $spUser->name : null);
            } elseif (isset($validated['supervisor_nama'])) {
                $report->supervisor_nama = $validated['supervisor_nama'];
            }

            $newStatus = $validated['status'] ?? $report->status;

            if ($newStatus === 'Selesai' && $oldStatus !== 'Selesai') {
                $report->tanggal_selesai_aktual = now();
            }

            $report->update($validated);

            if ($oldStatus !== $newStatus) {
                LaporanKerusakanHistory::create([
                    'laporan_id' => $report->id,
                    'user_id' => $user ? $user->id : null,
                    'action' => 'Pembaruan Status Laporan',
                    'status_from' => $oldStatus,
                    'status_to' => $newStatus,
                    'catatan' => 'Status laporan diperbarui menjadi ' . $newStatus,
                ]);
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Laporan kerusakan berhasil diperbarui.',
                'data' => $report->fresh(['asset', 'pelapor', 'penanggungJawab', 'supervisor', 'histories']),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Gagal memperbarui laporan: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Assign technician & target selesai (Supervisor / Admin flow).
     */
    public function assign(Request $request, $id)
    {
        $request->validate([
            'penanggung_jawab_id' => 'required|exists:users,id',
            'target_selesai' => 'required|date',
            'catatan' => 'nullable|string',
        ]);

        DB::beginTransaction();
        try {
            $report = LaporanKerusakanDataCenter::findOrFail($id);
            $pj = User::findOrFail($request->penanggung_jawab_id);
            $user = $request->user();

            $oldStatus = $report->status;
            $report->penanggung_jawab_id = $pj->id;
            $report->penanggung_jawab_nama = $pj->name;
            $report->target_selesai = $request->target_selesai;
            $report->status = 'Diproses';
            $report->save();

            LaporanKerusakanHistory::create([
                'laporan_id' => $report->id,
                'user_id' => $user ? $user->id : null,
                'action' => 'Penugasan Teknisi & Target Selesai',
                'status_from' => $oldStatus,
                'status_to' => 'Diproses',
                'catatan' => "Ditugaskan kepada {$pj->name} dengan target {$request->target_selesai}. Catatan: {$request->catatan}",
            ]);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Teknisi perbaikan berhasil ditugaskan.',
                'data' => $report,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Gagal menugaskan teknisi: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Approve / Validate report by Supervisor (Valid / Mengetahui -> Closed, or Reject -> Diproses).
     */
    public function validateReport(Request $request, $id)
    {
        $request->validate([
            'valid' => 'required|boolean',
            'catatan_validasi' => 'nullable|string',
            'supervisor_id' => 'nullable|exists:users,id',
        ]);

        DB::beginTransaction();
        try {
            $report = LaporanKerusakanDataCenter::findOrFail($id);
            $user = $request->user();
            $isAdmin = $user && ($user->role === 'admin' || $user->role === 'supervisor' || str_contains(strtolower($user->email ?? ''), 'admin'));

            // Hanya Supervisor / Admin yang berwenang memvalidasi laporan
            if (!$isAdmin) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Akses ditolak. Hanya Supervisor atau Administrator SMKI yang berwenang menandatangani validasi laporan (Mengetahui).',
                ], 403);
            }

            $oldStatus = $report->status;

            if ($request->valid) {
                $report->status = 'Closed';

                // Jika ada supervisor spesifik yang ditunjuk
                if ($request->filled('supervisor_id')) {
                    $spUser = User::find($request->supervisor_id);
                    $report->supervisor_id = $spUser ? $spUser->id : ($user ? $user->id : null);
                    $report->supervisor_nama = $spUser ? $spUser->name : ($user ? $user->name : 'Supervisor SMKI');
                } else {
                    $report->supervisor_id = $user ? $user->id : null;
                    $report->supervisor_nama = $user ? $user->name : 'Supervisor SMKI';
                }

                $report->catatan_validasi = $request->catatan_validasi ?? 'Hasil perbaikan telah diperiksa dan dinyatakan valid.';
                $report->validated_at = now();

                // Kembalikan status aset ke operasional
                if ($report->asset) {
                    $report->asset->update(['status_operasional' => 'Operasional']);
                }

                $action = 'Validasi & Penutupan Laporan (Closed)';
            } else {
                $report->status = 'Diproses';
                $report->catatan_validasi = $request->catatan_validasi ?? 'Hasil perbaikan belum valid, perlu ditindaklanjuti kembali.';
                $action = 'Validasi Ditolak (Kembali ke Teknisi)';
            }

            $report->save();

            LaporanKerusakanHistory::create([
                'laporan_id' => $report->id,
                'user_id' => $user ? $user->id : null,
                'action' => $action,
                'status_from' => $oldStatus,
                'status_to' => $report->status,
                'catatan' => $report->catatan_validasi,
            ]);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => $request->valid ? 'Laporan perbaikan berhasil disetujui dan disahkan (Closed).' : 'Laporan dikembalikan ke teknisi untuk perbaikan ulang.',
                'data' => $report->fresh(['asset', 'pelapor', 'penanggungJawab', 'supervisor', 'histories']),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Gagal memproses validasi laporan: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Remove the specified damage report.
     * Wewenang khusus Administrator.
     */
    public function destroy($id)
    {
        try {
            $user = request()->user();
            $isAdmin = $user && ($user->role === 'admin' || str_contains(strtolower($user->email ?? ''), 'admin'));

            if (!$isAdmin) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Akses ditolak. Hanya Administrator yang berwenang menghapus rekaman laporan kerusakan.',
                ], 403);
            }

            $report = LaporanKerusakanDataCenter::findOrFail($id);
            $report->delete();

            return response()->json([
                'status' => 'success',
                'message' => 'Laporan kerusakan berhasil dihapus.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Gagal menghapus laporan: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Ekspor 1 laporan kerusakan ke format Word FR-024.
     * Hanya dapat diekspor jika laporan telah divalidasi dan disahkan (Closed).
     */
    public function exportDocx($id)
    {
        try {
            $report = LaporanKerusakanDataCenter::with(['asset', 'pelapor', 'penanggungJawab', 'supervisor'])
                ->findOrFail($id);

            // Logika: Laporan HANYA dapat diekspor jika telah disahkan oleh Supervisor (Closed)
            $isDisahkan = ($report->status === 'Closed' || !empty($report->validated_at));
            if (!$isDisahkan) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Dokumen belum dapat diekspor. Laporan kerusakan harus divalidasi dan disahkan (Closed) terlebih dahulu oleh Supervisor sebelum dapat diekspor.',
                ], 422);
            }

            $docxPath = $this->docxService->generateSingleDocx($report);
            $fileName = 'FR-024_' . preg_replace('/[^a-zA-Z0-9_\-]/', '_', $report->nomor_laporan ?: 'Laporan_Kerusakan') . '.docx';

            return response()->download($docxPath, $fileName, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ])->deleteFileAfterSend(true);
        } catch (\Exception $e) {
            Log::error('Error exporting FR-024 DOCX: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Gagal mengekspor dokumen: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Ekspor daftar laporan kerusakan ke format Word FR-024 (Batch).
     * Wewenang khusus Administrator / Supervisor SMKI, hanya mengekspor laporan yang telah disahkan (Closed).
     */
    public function exportBatchDocx(Request $request)
    {
        try {
            $user = $request->user();
            $isAdmin = $user && ($user->role === 'admin' || str_contains(strtolower($user->email ?? ''), 'admin'));

            if (!$isAdmin) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Akses ditolak. Rekapitulasi audit laporan kerusakan hanya dapat diunduh oleh Administrator / Supervisor SMKI.',
                ], 403);
            }

            // Hanya sertakan laporan yang sudah sah (Closed)
            $query = LaporanKerusakanDataCenter::with(['asset', 'pelapor', 'penanggungJawab', 'supervisor'])
                ->where('status', 'Closed')
                ->orderBy('tanggal', 'desc')
                ->orderBy('jam', 'desc');

            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('nama_aset', 'like', "%{$search}%")
                        ->orWhere('kode_aset', 'like', "%{$search}%")
                        ->orWhere('penanggung_jawab_nama', 'like', "%{$search}%")
                        ->orWhere('pelapor_nama', 'like', "%{$search}%")
                        ->orWhere('nomor_laporan', 'like', "%{$search}%");
                });
            }

            $reports = $query->get();
            if ($reports->isEmpty()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Tidak ditemukan laporan yang telah disahkan (Closed) untuk diekspor.',
                ], 422);
            }

            $docxPath = $this->docxService->generateBatchDocx($reports, [
                'disusun' => 'Teknisi Data Center',
                'mengetahui' => $user ? $user->name : 'Kepala Bidang APTIKA',
                'tanggal' => now()->locale('id')->translatedFormat('d F Y'),
            ]);
            $fileName = 'FR-024_Rekapitulasi_Laporan_Kerusakan_' . date('Ymd_His') . '.docx';

            return response()->download($docxPath, $fileName, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ])->deleteFileAfterSend(true);
        } catch (\Exception $e) {
            Log::error('Error batch exporting FR-024 DOCX: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Gagal mengekspor rekapitulasi: ' . $e->getMessage(),
            ], 500);
        }
    }
}

