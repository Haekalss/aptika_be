<?php

namespace App\Http\Controllers;

use App\Models\DaftarAsetTi;
use App\Models\AsetTiNama;
use App\Models\AsetTiKlasifikasi;
use App\Models\AsetTiJenis;
use App\Models\AsetTiKategori;
use App\Models\AsetTiMerek;
use App\Models\AsetTiTipe;
use App\Models\AsetTiSpesifikasi;
use App\Models\AsetTiPemanfaatan;
use App\Models\AsetTiPenyedia;
use App\Models\AsetTiPenanggungJawab;
use App\Models\DataCenterAsset;
use App\Services\DaftarAsetTiExportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DaftarAsetTiController extends Controller
{
    protected DaftarAsetTiExportService $exportService;

    public function __construct(DaftarAsetTiExportService $exportService)
    {
        $this->exportService = $exportService;
    }

    /**
     * Menampilkan daftar aset TI dengan pencarian, filter kategori & lokasi,
     * filter rentang tanggal, paginasi, dan statistik total aset.
     */
    public function index(Request $request)
    {
        $query = DaftarAsetTi::with([
            'namaAsetRel',
            'klasifikasi',
            'jenis',
            'kategori',
            'merek',
            'tipe',
            'spesifikasi',
            'pemanfaatanRel',
            'penyedia',
            'penanggungJawab',
            'user:id,name,email',
        ])->orderBy('id', 'asc');

        // Filter Pencarian Teks (Nama, Kode, Nomor Seri, Merek, Tipe, PJ, Lokasi, Penyedia)
        if ($request->filled('search')) {
            $search = trim($request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('nama_aset', 'like', "%{$search}%")
                  ->orWhere('kode', 'like', "%{$search}%")
                  ->orWhere('no_seri', 'like', "%{$search}%")
                  ->orWhere('lokasi', 'like', "%{$search}%")
                  ->orWhere('spesifikasi_teknis', 'like', "%{$search}%")
                  ->orWhere('pemanfaatan', 'like', "%{$search}%")
                  ->orWhereHas('kategori', fn($k) => $k->where('nama_kategori', 'like', "%{$search}%"))
                  ->orWhereHas('merek', fn($m) => $m->where('nama_merek', 'like', "%{$search}%"))
                  ->orWhereHas('tipe', fn($t) => $t->where('nama_tipe', 'like', "%{$search}%"))
                  ->orWhereHas('penyedia', fn($p) => $p->where('nama_penyedia', 'like', "%{$search}%"))
                  ->orWhereHas('penanggungJawab', fn($pj) => $pj->where('nama_pj', 'like', "%{$search}%"));
            });
        }

        // Filter Kategori
        if ($request->filled('kategori_id')) {
            $query->where('kategori_id', $request->input('kategori_id'));
        } elseif ($request->filled('kategori')) {
            $kat = trim($request->input('kategori'));
            if ($kat !== 'Semua Kategori' && $kat !== '') {
                $query->whereHas('kategori', fn($k) => $k->where('nama_kategori', $kat));
            }
        }

        // Filter Lokasi
        if ($request->filled('lokasi')) {
            $lok = trim($request->input('lokasi'));
            if ($lok !== 'Lokasi Aset' && $lok !== 'Semua Lokasi' && $lok !== '') {
                $query->where('lokasi', 'like', "%{$lok}%");
            }
        }

        // Filter Rentang Tanggal (dari s.d. sampai)
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->input('date_to'));
        }

        // Paginasi atau Semua Data
        $perPage = (int) $request->input('per_page', 10);
        if ($perPage === -1) {
            $items = $query->get();
            $paginated = null;
        } else {
            $paginated = $query->paginate($perPage);
            $items = $paginated->items();
        }

        // Total Aset Keseluruhan (KPI Card)
        $totalAset = DaftarAsetTi::count();
        $totalPc = DaftarAsetTi::whereHas('kategori', function ($q) {
            $q->where('nama_kategori', 'like', '%PC%')
              ->orWhere('nama_kategori', 'like', '%Monitor%');
        })->count();
        $totalLaptop = DaftarAsetTi::whereHas('kategori', function ($q) {
            $q->where('nama_kategori', 'like', '%Laptop%')
              ->orWhere('nama_kategori', 'like', '%Note%');
        })->count();
        $totalPeripheral = max(0, $totalAset - ($totalPc + $totalLaptop));

        return response()->json([
            'success' => true,
            'data'    => $items,
            'stats'   => [
                'total_aset'       => $totalAset,
                'total_pc'         => $totalPc,
                'total_laptop'     => $totalLaptop,
                'total_peripheral' => $totalPeripheral,
            ],
            'meta'    => $paginated ? [
                'current_page' => $paginated->currentPage(),
                'last_page'    => $paginated->lastPage(),
                'per_page'     => $paginated->perPage(),
                'total'        => $paginated->total(),
                'from'         => $paginated->firstItem(),
                'to'           => $paginated->lastItem(),
            ] : [
                'current_page' => 1,
                'last_page'    => 1,
                'per_page'     => count($items),
                'total'        => count($items),
                'from'         => 1,
                'to'           => count($items),
            ],
        ]);
    }

    /**
     * Menyediakan data master lookups untuk dropdown formulir dan filter.
     */
    public function lookup()
    {
        $kategoris = AsetTiKategori::orderBy('nama_kategori')->get(['id', 'nama_kategori']);
        $klasifikasis = AsetTiKlasifikasi::orderBy('nama_klasifikasi')->get(['id', 'nama_klasifikasi']);
        $jeniss = AsetTiJenis::orderBy('nama_jenis')->get(['id', 'nama_jenis']);
        $mereks = AsetTiMerek::orderBy('nama_merek')->get(['id', 'nama_merek']);
        $tipes = AsetTiTipe::orderBy('nama_tipe')->get(['id', 'nama_tipe']);
        $penyedias = AsetTiPenyedia::orderBy('nama_penyedia')->get(['id', 'nama_penyedia']);
        $penanggungJawabs = AsetTiPenanggungJawab::orderBy('nama_pj')->get(['id', 'nama_pj']);

        // Ambil daftar lokasi unik yang sudah terdaftar
        $lokasis = DaftarAsetTi::whereNotNull('lokasi')
            ->where('lokasi', '!=', '')
            ->distinct()
            ->pluck('lokasi')
            ->sort()
            ->values();

        return response()->json([
            'success' => true,
            'data'    => [
                'kategoris'          => $kategoris,
                'klasifikasis'       => $klasifikasis,
                'jeniss'             => $jeniss,
                'mereks'             => $mereks,
                'tipes'              => $tipes,
                'penyedias'          => $penyedias,
                'penanggung_jawabs'  => $penanggungJawabs,
                'lokasis'            => $lokasis,
            ],
        ]);
    }

    /**
     * Menyimpan data aset TI baru ke database.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'kode'                      => 'nullable|string|max:100',
            'nama_aset'                 => 'required|string|max:255',
            'klasifikasi_id'            => 'nullable|integer',
            'klasifikasi_baru'          => 'nullable|string|max:100',
            'jenis_id'                  => 'nullable|integer',
            'jenis_baru'                => 'nullable|string|max:100',
            'kategori_id'               => 'nullable|integer',
            'kategori_baru'             => 'nullable|string|max:100',
            'no_seri'                   => 'nullable|string|max:150',
            'merek_id'                  => 'nullable|integer',
            'merek_baru'                => 'nullable|string|max:150',
            'tipe_id'                   => 'nullable|integer',
            'tipe_baru'                 => 'nullable|string|max:150',
            'penyedia_id'               => 'nullable|integer',
            'penyedia_baru'             => 'nullable|string|max:255',
            'tahun_pembelian'           => 'nullable|string|max:10',
            'penanggung_jawab_id'       => 'nullable|integer',
            'penanggung_jawab_baru'     => 'nullable|string|max:255',
            'lokasi'                    => 'nullable|string|max:255',
            'garansi'                   => 'nullable|string|max:100',
            'pemanfaatan'               => 'nullable|string',
            'pemanfaatan_id'            => 'nullable|integer',
            'tanggal_akhir_masa_pakai'  => 'nullable|string|max:100', // Batas Masa Pakai Produk
            'date_end'                  => 'nullable|string|max:100', // Batas Akhir Layanan Dukungan
            'spesifikasi_teknis'        => 'nullable|string',
        ]);

        // Auto-create lookups jika user memasukkan entri kustom baru
        if (empty($validated['klasifikasi_id']) && !empty($validated['klasifikasi_baru'])) {
            $item = AsetTiKlasifikasi::firstOrCreate(['nama_klasifikasi' => trim($validated['klasifikasi_baru'])]);
            $validated['klasifikasi_id'] = $item->id;
        }

        if (empty($validated['jenis_id']) && !empty($validated['jenis_baru'])) {
            $item = AsetTiJenis::firstOrCreate(['nama_jenis' => trim($validated['jenis_baru'])]);
            $validated['jenis_id'] = $item->id;
        }

        if (empty($validated['kategori_id']) && !empty($validated['kategori_baru'])) {
            $item = AsetTiKategori::firstOrCreate(['nama_kategori' => trim($validated['kategori_baru'])]);
            $validated['kategori_id'] = $item->id;
        }

        if (empty($validated['merek_id']) && !empty($validated['merek_baru'])) {
            $item = AsetTiMerek::firstOrCreate(['nama_merek' => trim($validated['merek_baru'])]);
            $validated['merek_id'] = $item->id;
        }

        if (empty($validated['tipe_id']) && !empty($validated['tipe_baru'])) {
            $item = AsetTiTipe::firstOrCreate(['nama_tipe' => trim($validated['tipe_baru'])]);
            $validated['tipe_id'] = $item->id;
        }

        if (empty($validated['penyedia_id']) && !empty($validated['penyedia_baru'])) {
            $item = AsetTiPenyedia::firstOrCreate(['nama_penyedia' => trim($validated['penyedia_baru'])]);
            $validated['penyedia_id'] = $item->id;
        }

        if (empty($validated['penanggung_jawab_id']) && !empty($validated['penanggung_jawab_baru'])) {
            $item = AsetTiPenanggungJawab::firstOrCreate(['nama_pj' => trim($validated['penanggung_jawab_baru'])]);
            $validated['penanggung_jawab_id'] = $item->id;
        }

        // Simpan juga ke master nama aset
        if (!empty($validated['nama_aset'])) {
            $namaAsetRecord = AsetTiNama::firstOrCreate(['nama_aset' => trim($validated['nama_aset'])]);
            $validated['nama_aset_id'] = $namaAsetRecord->id;
        }

        // Hapus key temporary `_baru`
        unset(
            $validated['klasifikasi_baru'],
            $validated['jenis_baru'],
            $validated['kategori_baru'],
            $validated['merek_baru'],
            $validated['tipe_baru'],
            $validated['penyedia_baru'],
            $validated['penanggung_jawab_baru']
        );

        $validated['user_id'] = auth()->id() ?? 1;
        $validated['bidang_id'] = auth()->user()?->bidang_id ?? 3; // APTIKA default

        $aset = DaftarAsetTi::create($validated);
        $aset->load([
            'namaAsetRel',
            'klasifikasi',
            'jenis',
            'kategori',
            'merek',
            'tipe',
            'spesifikasi',
            'pemanfaatanRel',
            'penyedia',
            'penanggungJawab',
            'user:id,name,email',
        ]);

        // Sinkronisasi otomatis ke Data Center Asset (Opsi 2 - Single Source of Truth)
        try {
            DataCenterAsset::syncFromDaftarAsetTi($aset);
        } catch (\Exception $e) {
            \Log::warning('Gagal auto-sync ke DataCenterAsset: ' . $e->getMessage());
        }

        return response()->json([
            'success' => true,
            'message' => 'Data aset TI berhasil ditambahkan ke inventaris.',
            'data'    => $aset,
        ], 201);
    }

    /**
     * Menampilkan detail lengkap suatu aset TI.
     */
    public function show($id)
    {
        $aset = DaftarAsetTi::with([
            'namaAsetRel',
            'klasifikasi',
            'jenis',
            'kategori',
            'merek',
            'tipe',
            'spesifikasi',
            'pemanfaatanRel',
            'penyedia',
            'penanggungJawab',
            'user:id,name,email',
        ])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data'    => $aset,
        ]);
    }

    /**
     * Memperbarui data aset TI.
     */
    public function update(Request $request, $id)
    {
        $aset = DaftarAsetTi::findOrFail($id);

        $validated = $request->validate([
            'kode'                      => 'nullable|string|max:100',
            'nama_aset'                 => 'sometimes|required|string|max:255',
            'klasifikasi_id'            => 'nullable|integer',
            'klasifikasi_baru'          => 'nullable|string|max:100',
            'jenis_id'                  => 'nullable|integer',
            'jenis_baru'                => 'nullable|string|max:100',
            'kategori_id'               => 'nullable|integer',
            'kategori_baru'             => 'nullable|string|max:100',
            'no_seri'                   => 'nullable|string|max:150',
            'merek_id'                  => 'nullable|integer',
            'merek_baru'                => 'nullable|string|max:150',
            'tipe_id'                   => 'nullable|integer',
            'tipe_baru'                 => 'nullable|string|max:150',
            'penyedia_id'               => 'nullable|integer',
            'penyedia_baru'             => 'nullable|string|max:255',
            'tahun_pembelian'           => 'nullable|string|max:10',
            'penanggung_jawab_id'       => 'nullable|integer',
            'penanggung_jawab_baru'     => 'nullable|string|max:255',
            'lokasi'                    => 'nullable|string|max:255',
            'garansi'                   => 'nullable|string|max:100',
            'pemanfaatan'               => 'nullable|string',
            'pemanfaatan_id'            => 'nullable|integer',
            'tanggal_akhir_masa_pakai'  => 'nullable|string|max:100',
            'date_end'                  => 'nullable|string|max:100',
            'spesifikasi_teknis'        => 'nullable|string',
        ]);

        if (empty($validated['klasifikasi_id']) && !empty($validated['klasifikasi_baru'])) {
            $item = AsetTiKlasifikasi::firstOrCreate(['nama_klasifikasi' => trim($validated['klasifikasi_baru'])]);
            $validated['klasifikasi_id'] = $item->id;
        }

        if (empty($validated['jenis_id']) && !empty($validated['jenis_baru'])) {
            $item = AsetTiJenis::firstOrCreate(['nama_jenis' => trim($validated['jenis_baru'])]);
            $validated['jenis_id'] = $item->id;
        }

        if (empty($validated['kategori_id']) && !empty($validated['kategori_baru'])) {
            $item = AsetTiKategori::firstOrCreate(['nama_kategori' => trim($validated['kategori_baru'])]);
            $validated['kategori_id'] = $item->id;
        }

        if (empty($validated['merek_id']) && !empty($validated['merek_baru'])) {
            $item = AsetTiMerek::firstOrCreate(['nama_merek' => trim($validated['merek_baru'])]);
            $validated['merek_id'] = $item->id;
        }

        if (empty($validated['tipe_id']) && !empty($validated['tipe_baru'])) {
            $item = AsetTiTipe::firstOrCreate(['nama_tipe' => trim($validated['tipe_baru'])]);
            $validated['tipe_id'] = $item->id;
        }

        if (empty($validated['penyedia_id']) && !empty($validated['penyedia_baru'])) {
            $item = AsetTiPenyedia::firstOrCreate(['nama_penyedia' => trim($validated['penyedia_baru'])]);
            $validated['penyedia_id'] = $item->id;
        }

        if (empty($validated['penanggung_jawab_id']) && !empty($validated['penanggung_jawab_baru'])) {
            $item = AsetTiPenanggungJawab::firstOrCreate(['nama_pj' => trim($validated['penanggung_jawab_baru'])]);
            $validated['penanggung_jawab_id'] = $item->id;
        }

        if (!empty($validated['nama_aset'])) {
            $namaAsetRecord = AsetTiNama::firstOrCreate(['nama_aset' => trim($validated['nama_aset'])]);
            $validated['nama_aset_id'] = $namaAsetRecord->id;
        }

        unset(
            $validated['klasifikasi_baru'],
            $validated['jenis_baru'],
            $validated['kategori_baru'],
            $validated['merek_baru'],
            $validated['tipe_baru'],
            $validated['penyedia_baru'],
            $validated['penanggung_jawab_baru']
        );

        $aset->update($validated);
        $aset->load([
            'namaAsetRel',
            'klasifikasi',
            'jenis',
            'kategori',
            'merek',
            'tipe',
            'spesifikasi',
            'pemanfaatanRel',
            'penyedia',
            'penanggungJawab',
            'user:id,name,email',
        ]);

        // Sinkronisasi otomatis ke Data Center Asset (Opsi 2 - Single Source of Truth)
        try {
            DataCenterAsset::syncFromDaftarAsetTi($aset);
        } catch (\Exception $e) {
            \Log::warning('Gagal auto-sync ke DataCenterAsset: ' . $e->getMessage());
        }

        return response()->json([
            'success' => true,
            'message' => 'Data aset TI berhasil diperbarui.',
            'data'    => $aset,
        ]);
    }

    /**
     * Menghapus aset TI (Soft Delete).
     */
    public function destroy($id)
    {
        $aset = DaftarAsetTi::findOrFail($id);
        $aset->delete();

        if (!empty($aset->kode)) {
            DataCenterAsset::where('kode_aset', $aset->kode)->delete();
        }

        return response()->json([
            'success' => true,
            'message' => 'Data aset TI berhasil dihapus dari inventaris.',
        ]);
    }

    /**
     * Ekspor data inventaris aset ke berkas Excel (.xlsx).
     * Mendukung ekspor "Semua Data" atau "Data Sesuai Filter" serta rentang tanggal.
     */
    public function exportExcel(Request $request)
    {
        $exportMode = $request->input('export_mode', 'filtered'); // 'all' atau 'filtered'

        $query = DaftarAsetTi::with([
            'namaAsetRel',
            'klasifikasi',
            'jenis',
            'kategori',
            'merek',
            'tipe',
            'spesifikasi',
            'pemanfaatanRel',
            'penyedia',
            'penanggungJawab',
        ])->orderBy('id', 'asc');

        if ($exportMode === 'filtered') {
            // Terapkan filter pencarian
            if ($request->filled('search')) {
                $search = trim($request->input('search'));
                $query->where(function ($q) use ($search) {
                    $q->where('nama_aset', 'like', "%{$search}%")
                      ->orWhere('kode', 'like', "%{$search}%")
                      ->orWhere('no_seri', 'like', "%{$search}%")
                      ->orWhere('lokasi', 'like', "%{$search}%")
                      ->orWhereHas('kategori', fn($k) => $k->where('nama_kategori', 'like', "%{$search}%"))
                      ->orWhereHas('merek', fn($m) => $m->where('nama_merek', 'like', "%{$search}%"))
                      ->orWhereHas('tipe', fn($t) => $t->where('nama_tipe', 'like', "%{$search}%"))
                      ->orWhereHas('penyedia', fn($p) => $p->where('nama_penyedia', 'like', "%{$search}%"))
                      ->orWhereHas('penanggungJawab', fn($pj) => $pj->where('nama_pj', 'like', "%{$search}%"));
                });
            }

            if ($request->filled('kategori_id')) {
                $query->where('kategori_id', $request->input('kategori_id'));
            } elseif ($request->filled('kategori')) {
                $kat = trim($request->input('kategori'));
                if ($kat !== 'Semua Kategori' && $kat !== '') {
                    $query->whereHas('kategori', fn($k) => $k->where('nama_kategori', $kat));
                }
            }

            if ($request->filled('lokasi')) {
                $lok = trim($request->input('lokasi'));
                if ($lok !== 'Lokasi Aset' && $lok !== 'Semua Lokasi' && $lok !== '') {
                    $query->where('lokasi', 'like', "%{$lok}%");
                }
            }

            if ($request->filled('date_from')) {
                $query->whereDate('created_at', '>=', $request->input('date_from'));
            }
            if ($request->filled('date_to')) {
                $query->whereDate('created_at', '<=', $request->input('date_to'));
            }
        }

        $items = $query->get();

        $options = [
            'date_from'   => $request->input('date_from'),
            'date_to'     => $request->input('date_to'),
            'export_mode' => $exportMode,
        ];

        try {
            $filePath = $this->exportService->generateExcel($items, $options);
            $filename = 'Daftar_Aset_TI_Bidang_APTIKA_' . date('Ymd_His') . '.xlsx';

            return response()->download($filePath, $filename, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])->deleteFileAfterSend(true);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal menghasilkan berkas Excel: ' . $e->getMessage(),
            ], 500);
        }
    }
}
