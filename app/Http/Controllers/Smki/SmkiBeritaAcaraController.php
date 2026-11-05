<?php

namespace App\Http\Controllers\Smki;

use App\Http\Controllers\Controller;
use App\Models\BeritaAcara;
use App\Models\DetailMedia;
use App\Models\User;
use App\Services\SmkiBaMediaDocxService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SmkiBeritaAcaraController extends Controller
{
    protected SmkiBaMediaDocxService $docxService;

    public function __construct(SmkiBaMediaDocxService $docxService)
    {
        $this->docxService = $docxService;
    }

    /**
     * Menampilkan daftar Berita Acara Penghancuran Media dengan live search,
     * paginasi, dan ringkasan KPI (Total Data, Data Hari Ini, Data Dihapus).
     */
    public function index(Request $request)
    {
        $query = BeritaAcara::with([
            'detailMedia',
            'pelaksana:id,name,email,position',
            'diketahui:id,name,email,position',
            'bidang:id,name,code',
        ])
        ->orderBy('created_at', 'desc')
        ->orderBy('id_ba', 'desc');

        // Live Search Multi-kolom
        if ($request->filled('search')) {
            $search = trim($request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('nomor_dokumen', 'like', "%{$search}%")
                  ->orWhere('alasan_penghancuran', 'like', "%{$search}%")
                  ->orWhere('nama_pelaksana', 'like', "%{$search}%")
                  ->orWhere('nama_diketahui', 'like', "%{$search}%")
                  ->orWhereHas('pelaksana', fn($p) => $p->where('name', 'like', "%{$search}%"))
                  ->orWhereHas('diketahui', fn($d) => $d->where('name', 'like', "%{$search}%"))
                  ->orWhereHas('detailMedia', function ($m) use ($search) {
                      $m->where('nama_perangkat', 'like', "%{$search}%")
                        ->orWhere('spesifikasi', 'like', "%{$search}%")
                        ->orWhere('serial_number', 'like', "%{$search}%")
                        ->orWhere('keterangan', 'like', "%{$search}%");
                  });
            });
        }

        // Filter Tanggal Pelaksanaan
        if ($request->filled('date_from')) {
            $query->whereDate('tanggal_pelaksanaan', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('tanggal_pelaksanaan', '<=', $request->input('date_to'));
        }

        // Paginasi
        $perPage = (int) $request->input('per_page', 10);
        if ($perPage === -1) {
            $items = $query->get();
            $paginated = null;
        } else {
            $paginated = $query->paginate($perPage);
            $items = $paginated->items();
        }

        // Hitung Ringkasan KPI
        $today = Carbon::today()->toDateString();
        $totalData = BeritaAcara::count();
        $dataHariIni = BeritaAcara::whereDate('created_at', $today)->count();
        $dataDihapus = BeritaAcara::onlyTrashed()->count();

        return response()->json([
            'success' => true,
            'data'    => $items,
            'stats'   => [
                'total_data'    => $totalData,
                'data_hari_ini' => $dataHariIni,
                'data_dihapus'  => $dataDihapus,
            ],
            'meta'    => $paginated ? [
                'current_page' => $paginated->currentPage(),
                'last_page'    => $paginated->lastPage(),
                'per_page'     => $paginated->perPage(),
                'total'        => $paginated->total(),
            ] : [
                'total' => count($items),
            ],
        ]);
    }

    /**
     * Mengambil data master dropdown lookup (Pegawai/Users, auto-generated No. Dokumen).
     */
    public function lookup()
    {
        // Ambil daftar user aktif untuk pilihan pelaksana & diketahui
        $users = User::select('id', 'name', 'email', 'position')
            ->orderBy('name', 'asc')
            ->get();

        // Rekomendasi Nomor Dokumen Otomatis
        $currentYear = date('Y');
        $countThisYear = BeritaAcara::withTrashed()
            ->whereYear('created_at', $currentYear)
            ->count();
        $nextSequence = str_pad($countThisYear + 1, 3, '0', STR_PAD_LEFT);
        $recommendedDocNo = "BA-{$nextSequence}/SMKI/{$currentYear}";

        return response()->json([
            'success' => true,
            'data'    => [
                'users'              => $users,
                'recommended_doc_no' => $recommendedDocNo,
                'default_revisi'     => '1.0',
                'default_berlaku'    => date('d F Y'),
            ],
        ]);
    }

    /**
     * Menyimpan data Berita Acara dan detail media baru (One-to-Many).
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'nomor_dokumen'        => 'nullable|string|max:100',
            'tanggal_pelaksanaan'  => 'required|date',
            'alasan_penghancuran'  => 'required|string',
            'id_pelaksana'         => 'nullable|exists:users,id',
            'id_diketahui'         => 'nullable|exists:users,id',
            'nama_pelaksana'       => 'nullable|string|max:255',
            'nip_pelaksana'        => 'nullable|string|max:50',
            'nama_diketahui'       => 'nullable|string|max:255',
            'nip_diketahui'        => 'nullable|string|max:50',
            'detail_media'         => 'required|array|min:1',
            'detail_media.*.nama_perangkat' => 'required|string|max:255',
            'detail_media.*.spesifikasi'    => 'nullable|string|max:255',
            'detail_media.*.jenis_media'    => 'nullable|string|max:100',
            'detail_media.*.serial_number'  => 'nullable|string|max:255',
            'detail_media.*.jumlah'         => 'required|integer|min:1',
            'detail_media.*.satuan'         => 'nullable|string|max:50',
            'detail_media.*.keterangan'     => 'nullable|string',
        ], [
            'tanggal_pelaksanaan.required' => 'Tanggal pelaksanaan wajib diisi.',
            'alasan_penghancuran.required' => 'Alasan penghancuran wajib diisi.',
            'detail_media.required'        => 'Rincian media yang dihancurkan wajib diisi minimal 1 perangkat.',
            'detail_media.min'             => 'Rincian media yang dihancurkan wajib diisi minimal 1 perangkat.',
            'detail_media.*.nama_perangkat.required' => 'Nama perangkat pada setiap baris media wajib diisi.',
            'detail_media.*.jumlah.required'         => 'Jumlah perangkat wajib diisi dan minimal 1.',
        ]);

        // Auto-generate Nomor Dokumen jika tidak diisi
        if (empty($validated['nomor_dokumen'])) {
            $currentYear = date('Y', strtotime($validated['tanggal_pelaksanaan']));
            $count = BeritaAcara::withTrashed()->whereYear('created_at', $currentYear)->count();
            $seq = str_pad($count + 1, 3, '0', STR_PAD_LEFT);
            $validated['nomor_dokumen'] = "BA-{$seq}/SMKI/{$currentYear}";
        }

        // Resolusi Nama Pelaksana jika user dipilih
        if (!empty($validated['id_pelaksana']) && empty($validated['nama_pelaksana'])) {
            $u = User::find($validated['id_pelaksana']);
            $validated['nama_pelaksana'] = $u?->name;
        }

        // Resolusi Nama Yang Mengetahui jika user dipilih
        if (!empty($validated['id_diketahui']) && empty($validated['nama_diketahui'])) {
            $u = User::find($validated['id_diketahui']);
            $validated['nama_diketahui'] = $u?->name;
        }

        $validated['bidang_id'] = auth()->user()?->bidang_id ?? 3;

        $mediaItems = $validated['detail_media'];
        unset($validated['detail_media']);

        DB::beginTransaction();
        try {
            $ba = BeritaAcara::create($validated);

            $noUrut = 1;
            foreach ($mediaItems as $item) {
                DetailMedia::create([
                    'id_pelaksanaan' => $ba->id_ba,
                    'no_urut'        => $noUrut++,
                    'nama_perangkat' => trim($item['nama_perangkat']),
                    'spesifikasi'    => $item['spesifikasi'] ?? null,
                    'jenis_media'    => $item['jenis_media'] ?? null,
                    'serial_number'  => $item['serial_number'] ?? null,
                    'jumlah'         => (int) ($item['jumlah'] ?? 1),
                    'satuan'         => $item['satuan'] ?? 'Unit',
                    'keterangan'     => $item['keterangan'] ?? null,
                ]);
            }

            DB::commit();

            $ba->load(['detailMedia', 'pelaksana', 'diketahui']);

            return response()->json([
                'success' => true,
                'message' => 'Berita Acara Penghancuran Media berhasil disimpan.',
                'data'    => $ba,
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Gagal menyimpan Berita Acara: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Menampilkan rincian tunggal Berita Acara beserta media items.
     */
    public function show($id)
    {
        $ba = BeritaAcara::with([
            'detailMedia',
            'pelaksana:id,name,email,position',
            'diketahui:id,name,email,position',
            'bidang:id,name,code',
        ])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data'    => $ba,
        ]);
    }

    /**
     * Memperbarui data Berita Acara dan daftar rincian media.
     */
    public function update(Request $request, $id)
    {
        $ba = BeritaAcara::findOrFail($id);

        $validated = $request->validate([
            'nomor_dokumen'        => 'sometimes|nullable|string|max:100',
            'tanggal_pelaksanaan'  => 'sometimes|required|date',
            'alasan_penghancuran'  => 'sometimes|required|string',
            'id_pelaksana'         => 'nullable|exists:users,id',
            'id_diketahui'         => 'nullable|exists:users,id',
            'nama_pelaksana'       => 'nullable|string|max:255',
            'nip_pelaksana'        => 'nullable|string|max:50',
            'nama_diketahui'       => 'nullable|string|max:255',
            'nip_diketahui'        => 'nullable|string|max:50',
            'detail_media'         => 'sometimes|required|array|min:1',
            'detail_media.*.nama_perangkat' => 'required|string|max:255',
            'detail_media.*.spesifikasi'    => 'nullable|string|max:255',
            'detail_media.*.jenis_media'    => 'nullable|string|max:100',
            'detail_media.*.serial_number'  => 'nullable|string|max:255',
            'detail_media.*.jumlah'         => 'required|integer|min:1',
            'detail_media.*.satuan'         => 'nullable|string|max:50',
            'detail_media.*.keterangan'     => 'nullable|string',
        ]);

        if (array_key_exists('id_pelaksana', $validated) && !empty($validated['id_pelaksana']) && empty($validated['nama_pelaksana'])) {
            $u = User::find($validated['id_pelaksana']);
            $validated['nama_pelaksana'] = $u?->name;
        }

        if (array_key_exists('id_diketahui', $validated) && !empty($validated['id_diketahui']) && empty($validated['nama_diketahui'])) {
            $u = User::find($validated['id_diketahui']);
            $validated['nama_diketahui'] = $u?->name;
        }

        DB::beginTransaction();
        try {
            $mediaItems = $validated['detail_media'] ?? null;
            unset($validated['detail_media']);

            $ba->update($validated);

            if ($mediaItems !== null) {
                // Hapus dan gantikan media items lama
                DetailMedia::where('id_pelaksanaan', $ba->id_ba)->delete();

                $noUrut = 1;
                foreach ($mediaItems as $item) {
                    DetailMedia::create([
                        'id_pelaksanaan' => $ba->id_ba,
                        'no_urut'        => $noUrut++,
                        'nama_perangkat' => trim($item['nama_perangkat']),
                        'spesifikasi'    => $item['spesifikasi'] ?? null,
                        'jenis_media'    => $item['jenis_media'] ?? null,
                        'serial_number'  => $item['serial_number'] ?? null,
                        'jumlah'         => (int) ($item['jumlah'] ?? 1),
                        'satuan'         => $item['satuan'] ?? 'Unit',
                        'keterangan'     => $item['keterangan'] ?? null,
                    ]);
                }
            }

            DB::commit();

            $ba->load(['detailMedia', 'pelaksana', 'diketahui']);

            return response()->json([
                'success' => true,
                'message' => 'Berita Acara berhasil diperbarui.',
                'data'    => $ba,
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Gagal memperbarui Berita Acara: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Menghapus Berita Acara (Soft delete, cascading detail media).
     */
    public function destroy($id)
    {
        $ba = BeritaAcara::findOrFail($id);
        $nomor = $ba->nomor_dokumen;

        DB::beginTransaction();
        try {
            // Soft delete berita acara
            $ba->delete();
            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "Berita acara {$nomor} berhasil dihapus.",
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus berita acara: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Ekspor dokumen Berita Acara ke format DOCX resmi FR014-SMKI.
     */
    public function exportDocx(Request $request, $id = null)
    {
        $targetId = $id ?: $request->input('id');

        if (!$targetId) {
            // Jika tidak ada ID spesifik, ambil data paling akhir atau pertama yang cocok dengan pencarian
            $targetBa = BeritaAcara::with(['detailMedia', 'pelaksana', 'diketahui'])
                ->orderBy('created_at', 'desc')
                ->first();
        } else {
            $targetBa = BeritaAcara::with(['detailMedia', 'pelaksana', 'diketahui'])
                ->findOrFail($targetId);
        }

        if (!$targetBa) {
            return response()->json([
                'success' => false,
                'message' => 'Tidak ada data berita acara untuk diekspor.',
            ], 404);
        }

        $options = [];
        if ($request->filled('no_dokumen')) {
            $options['no_dokumen'] = $request->input('no_dokumen');
        }
        if ($request->filled('no_revisi')) {
            $options['no_revisi'] = $request->input('no_revisi');
        }
        if ($request->filled('tanggal_berlaku')) {
            $options['tanggal_berlaku'] = $request->input('tanggal_berlaku');
        }

        try {
            $filePath = $this->docxService->generateDocx($targetBa, $options);
            $cleanDocNo = preg_replace('/[^A-Za-z0-9_\-]/', '_', $targetBa->nomor_dokumen ?: 'FR014');
            $filename = "FR014_Berita_Acara_Penghancuran_Media_{$cleanDocNo}_" . date('Ymd_His') . ".docx";

            return response()->download($filePath, $filename, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ])->deleteFileAfterSend(true);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal menghasilkan dokumen FR-014: ' . $e->getMessage(),
            ], 500);
        }
    }
}
