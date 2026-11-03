<?php

namespace App\Http\Controllers\Smki;

use App\Http\Controllers\Controller;
use App\Models\Pegawai;
use App\Models\SmkiHakAksesFisik;
use App\Models\SmkiRuanganArea;
use App\Services\SmkiHakAksesFisikDocxService;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SmkiHakAksesFisikController extends Controller
{
    public function __construct(
        private SmkiHakAksesFisikDocxService $docxService
    ) {
    }

    /**
     * Tampilkan daftar pengajuan Formulir Hak Akses Fisik (FR-019).
     */
    public function index(Request $request)
    {
        try {
            $query = SmkiHakAksesFisik::query()->where('is_deleted', false);

            if ($request->filled('search')) {
                $search = trim($request->query('search'));
                $query->where(function ($q) use ($search) {
                    $q->where('nama_pemohon', 'like', "%{$search}%")
                        ->orWhere('nomor_dokumen', 'like', "%{$search}%")
                        ->orWhere('unit_kerja', 'like', "%{$search}%")
                        ->orWhere('jabatan', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            }

            if ($request->filled('jenis_permintaan')) {
                $query->where('jenis_permintaan', $request->query('jenis_permintaan'));
            }

            if ($request->filled('sifat_akses')) {
                $query->where('sifat_akses', $request->query('sifat_akses'));
            }

            if ($request->filled('status')) {
                $query->where('status_pengajuan', $request->query('status'));
            }

            // Stats metrik (Total, Hari Ini, Dihapus)
            $totalData   = SmkiHakAksesFisik::where('is_deleted', false)->count();
            $dataHariIni = SmkiHakAksesFisik::where('is_deleted', false)
                ->whereDate('created_at', Carbon::today())
                ->count();
            $dataDihapus = SmkiHakAksesFisik::where('is_deleted', true)->count();

            $perPage = max(1, min(100, (int) $request->query('per_page', 10)));
            $paginated = $query->orderByDesc('id')->paginate($perPage);

            return response()->json([
                'success' => true,
                'message' => 'Data Formulir Hak Akses Fisik berhasil dimuat.',
                'stats' => [
                    'total' => $totalData,
                    'hari_ini' => $dataHariIni,
                    'dihapus' => $dataDihapus,
                ],
                'data' => $paginated->items(),
                'meta' => [
                    'current_page' => $paginated->currentPage(),
                    'last_page'    => $paginated->lastPage(),
                    'per_page'     => $paginated->perPage(),
                    'total'        => $paginated->total(),
                ],
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat data Formulir Hak Akses Fisik: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Master lookup untuk unite pegawai, daftar ruangan, dan default header kontrol.
     */
    public function lookup()
    {
        try {
            $pegawais = Pegawai::orderBy('nama', 'asc')->get([
                'id',
                'nama',
                'nip',
                'jabatan',
                'pangkat',
                'role',
            ]);

            $ruanganList = SmkiRuanganArea::orderBy('id', 'asc')->get([
                'id',
                'kode_ruangan',
                'nama_ruangan',
                'deskripsi_area',
            ]);

            // Jika ruangan kosong, fallback ke default template
            if ($ruanganList->isEmpty()) {
                $ruanganList = collect([
                    ['nama_ruangan' => 'Data Center'],
                    ['nama_ruangan' => 'Ruang NOC'],
                    ['nama_ruangan' => 'UPS'],
                    ['nama_ruangan' => 'Akses Genset Gedung'],
                    ['nama_ruangan' => 'Pintu Utama'],
                    ['nama_ruangan' => 'Ruang SOC'],
                    ['nama_ruangan' => 'Akses Genset DC'],
                ]);
            }

            $currentYear = date('Y');
            $count = SmkiHakAksesFisik::whereYear('created_at', $currentYear)->count() + 1;
            $nextNomorDokumen = sprintf('FR-019/KOM/%s/%04d', $currentYear, $count);

            return response()->json([
                'success' => true,
                'data' => [
                    'pegawai' => $pegawais,
                    'ruangan' => $ruanganList,
                    'keperluan_options' => [
                        'Instansi',
                        'Monitoring',
                        'Perawatan',
                        'Lainnya',
                    ],
                    'default_header' => [
                        'no_dokumen'       => 'FR-019//KOM.03.05/SANDIKAMI',
                        'no_revisi'        => '1.1',
                        'tanggal_berlaku'  => '2022-07-07',
                    ],
                    'next_nomor_dokumen' => $nextNomorDokumen,
                ],
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat master lookup: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Simpan pengajuan Hak Akses Fisik baru.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'nomor_dokumen'        => 'nullable|string|max:100',
            'tanggal_pengajuan'    => 'required|date',
            'pegawai_id'           => 'nullable|exists:pegawai,id',
            'nama_pemohon'         => 'required|string|max:150',
            'nip'                  => 'nullable|string|max:50',
            'jabatan'              => 'nullable|string|max:100',
            'unit_kerja'           => 'nullable|string|max:150',
            'no_handphone'         => 'nullable|string|max:50',
            'email'                => 'nullable|email|max:100',
            'jenis_permintaan'     => 'nullable|string|in:Pendaftaran,Penutupan',
            'sifat_akses'          => 'nullable|string|in:Permanen,Sementara',
            'waktu_akses'          => 'nullable|string|max:100',
            'waktu_akses_lainnya'  => 'nullable|string|max:255',
            'berlaku_sampai'       => 'nullable|date',
            'ruangan_akses'        => 'nullable|array',
            'keperluan_alasan'     => 'nullable|array',
            'keperluan_lainnya'    => 'nullable|string',
            'status_pengajuan'     => 'nullable|string|in:Pending,Approved,Rejected',
            'catatan'              => 'nullable|string',

            // Tanda tangan
            'pemohon_nama'         => 'nullable|string|max:150',
            'pemohon_nip'          => 'nullable|string|max:50',
            'atasan_nama'          => 'nullable|string|max:150',
            'atasan_nip'           => 'nullable|string|max:50',
            'penanggung_jawab_nama'=> 'nullable|string|max:150',
            'penanggung_jawab_nip' => 'nullable|string|max:50',
            'agen_nama'            => 'nullable|string|max:150',
            'agen_nip'             => 'nullable|string|max:50',

            // Header metadata
            'header_no_dokumen'    => 'nullable|string|max:100',
            'header_no_revisi'     => 'nullable|string|max:20',
            'header_tanggal_berlaku' => 'nullable|date',
        ]);

        try {
            if (empty($validated['nomor_dokumen'])) {
                $year  = date('Y');
                $count = SmkiHakAksesFisik::whereYear('created_at', $year)->count() + 1;
                $validated['nomor_dokumen'] = sprintf('FR-019/KOM/%s/%04d', $year, $count);
            }

            if (empty($validated['header_no_dokumen'])) {
                $validated['header_no_dokumen'] = 'FR-019//KOM.03.05/SANDIKAMI';
            }
            if (empty($validated['header_no_revisi'])) {
                $validated['header_no_revisi'] = '1.1';
            }
            if (empty($validated['header_tanggal_berlaku'])) {
                $validated['header_tanggal_berlaku'] = '2022-07-07';
            }

            if (empty($validated['pemohon_nama'])) {
                $validated['pemohon_nama'] = $validated['nama_pemohon'];
            }
            if (empty($validated['pemohon_nip'])) {
                $validated['pemohon_nip'] = $validated['nip'] ?? null;
            }

            $user = $request->user();
            $validated['user_id']   = $user?->id;
            $validated['bidang_id'] = $user?->bidang_id ?: 3; // Default APTIKA

            $form = SmkiHakAksesFisik::create($validated);

            return response()->json([
                'success' => true,
                'message' => 'Pengajuan Hak Akses Fisik (FR-019) berhasil disimpan.',
                'data'    => $form->load('pegawai'),
            ], 201);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal menyimpan pengajuan: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Tampilkan detail satu dokumen pengajuan.
     */
    public function show($id)
    {
        try {
            $item = SmkiHakAksesFisik::with(['pegawai', 'bidang', 'user'])->findOrFail($id);

            return response()->json([
                'success' => true,
                'data'    => $item,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Data tidak ditemukan: ' . $e->getMessage(),
            ], 404);
        }
    }

    /**
     * Perbarui pengajuan Hak Akses Fisik.
     */
    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'nomor_dokumen'        => 'required|string|max:100',
            'tanggal_pengajuan'    => 'required|date',
            'pegawai_id'           => 'nullable|exists:pegawai,id',
            'nama_pemohon'         => 'required|string|max:150',
            'nip'                  => 'nullable|string|max:50',
            'jabatan'              => 'nullable|string|max:100',
            'unit_kerja'           => 'nullable|string|max:150',
            'no_handphone'         => 'nullable|string|max:50',
            'email'                => 'nullable|email|max:100',
            'jenis_permintaan'     => 'nullable|string|in:Pendaftaran,Penutupan',
            'sifat_akses'          => 'nullable|string|in:Permanen,Sementara',
            'waktu_akses'          => 'nullable|string|max:100',
            'waktu_akses_lainnya'  => 'nullable|string|max:255',
            'berlaku_sampai'       => 'nullable|date',
            'ruangan_akses'        => 'nullable|array',
            'keperluan_alasan'     => 'nullable|array',
            'keperluan_lainnya'    => 'nullable|string',
            'status_pengajuan'     => 'nullable|string|in:Pending,Approved,Rejected',
            'catatan'              => 'nullable|string',

            // Tanda tangan
            'pemohon_nama'         => 'nullable|string|max:150',
            'pemohon_nip'          => 'nullable|string|max:50',
            'atasan_nama'          => 'nullable|string|max:150',
            'atasan_nip'           => 'nullable|string|max:50',
            'penanggung_jawab_nama'=> 'nullable|string|max:150',
            'penanggung_jawab_nip' => 'nullable|string|max:50',
            'agen_nama'            => 'nullable|string|max:150',
            'agen_nip'             => 'nullable|string|max:50',

            // Header metadata
            'header_no_dokumen'    => 'nullable|string|max:100',
            'header_no_revisi'     => 'nullable|string|max:20',
            'header_tanggal_berlaku' => 'nullable|date',
        ]);

        try {
            $item = SmkiHakAksesFisik::findOrFail($id);
            $item->update($validated);

            return response()->json([
                'success' => true,
                'message' => 'Perubahan data pengajuan berhasil disimpan.',
                'data'    => $item->load('pegawai'),
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memperbarui pengajuan: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Hapus (soft delete) data pengajuan.
     */
    public function destroy($id)
    {
        try {
            $item = SmkiHakAksesFisik::findOrFail($id);
            // Tandai is_deleted = true untuk metrik card "DATA DIHAPUS"
            $item->is_deleted = true;
            $item->save();
            $item->delete(); // Soft delete

            return response()->json([
                'success' => true,
                'message' => 'Data pengajuan berhasil dihapus.',
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus data: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Ekspor file Microsoft Word (.docx) terisi sesuai template resmi FR-019.
     */
    public function exportDocx(Request $request)
    {
        try {
            $id = $request->query('id');

            if ($id) {
                $form = SmkiHakAksesFisik::findOrFail($id);
            } else {
                $form = SmkiHakAksesFisik::where('is_deleted', false)->latest('id')->first();
                if (!$form) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Belum ada data pengajuan yang tersimpan untuk diekspor.',
                    ], 404);
                }
            }

            // Izinkan override kontrol header dari modal ekspor
            if ($request->filled('no_dokumen')) {
                $form->header_no_dokumen = $request->query('no_dokumen');
            }
            if ($request->filled('no_revisi')) {
                $form->header_no_revisi = $request->query('no_revisi');
            }
            if ($request->filled('tanggal_berlaku')) {
                $form->header_tanggal_berlaku = $request->query('tanggal_berlaku');
            }

            $filePath = $this->docxService->generateDocx($form);

            $safeName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $form->nama_pemohon ?: 'Pemohon');
            $filename = "FR-019_{$safeName}.docx";

            return response()->download($filePath, $filename, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ])->deleteFileAfterSend(true);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal menghasilkan dokumen Word (.docx): ' . $e->getMessage(),
            ], 500);
        }
    }
}
