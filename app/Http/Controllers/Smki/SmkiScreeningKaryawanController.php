<?php

namespace App\Http\Controllers\Smki;

use App\Http\Controllers\Controller;
use App\Models\ScreeningKaryawan;
use App\Models\DetailScreening;
use App\Models\Jabatan;
use App\Models\MasterBidang;
use App\Models\ItemScreening;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class SmkiScreeningKaryawanController extends Controller
{
    /**
     * Menampilkan daftar Screening Karyawan Outsourcing dengan fitur live search,
     * filter periode, bidang, kelengkapan, paginasi, dan statistik KPI.
     */
    public function index(Request $request)
    {
        $query = ScreeningKaryawan::with([
            'jabatan',
            'bidang',
            'details.item',
        ])
        ->orderBy('tanggal_screening', 'desc')
        ->orderBy('id_screening', 'desc');

        // 1. Live Search Multi-kolom (Nama, Jabatan, Bidang, Periode, Catatan)
        if ($request->filled('search')) {
            $search = trim($request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('nama_karyawan', 'like', "%{$search}%")
                  ->orWhere('tahun', 'like', "%{$search}%")
                  ->orWhere('catatan', 'like', "%{$search}%")
                  ->orWhere('kesimpulan', 'like', "%{$search}%")
                  ->orWhereHas('jabatan', function ($j) use ($search) {
                      $j->where('nama_jabatan', 'like', "%{$search}%");
                  })
                  ->orWhereHas('bidang', function ($b) use ($search) {
                      $b->where('nama_bidang', 'like', "%{$search}%");
                  });
            });
        }

        // 2. Filter Periode / Tahun
        if ($request->filled('tahun') && $request->input('tahun') !== 'ALL') {
            $query->where('tahun', $request->input('tahun'));
        }

        // 3. Filter Bidang
        if ($request->filled('id_bidang') && $request->input('id_bidang') !== 'ALL') {
            $query->where('id_bidang', $request->input('id_bidang'));
        }

        // 4. Filter Kelengkapan / Kesimpulan
        if ($request->filled('kesimpulan') && $request->input('kesimpulan') !== 'ALL') {
            $query->where('kesimpulan', $request->input('kesimpulan'));
        }

        // Paginasi (default 5 sesuai layout mockup atau 10/sesuai request)
        $perPage = (int) $request->input('per_page', 5);
        if ($perPage === -1) {
            $items = $query->get();
            $paginated = null;
        } else {
            $paginated = $query->paginate($perPage);
            $items = $paginated->items();
        }

        // Statistik Ringkasan KPI Dashboard
        $totalScreening = ScreeningKaryawan::count();
        $totalKaryawan = ScreeningKaryawan::distinct('nama_karyawan')->count('nama_karyawan');
        $activeYear = ScreeningKaryawan::max('tahun') ?? 2026;
        $statusLengkap = ScreeningKaryawan::where('kesimpulan', 'Administrasi Lengkap')->count();
        $statusPerlu = ScreeningKaryawan::where('kesimpulan', 'Perlu melengkapi administrasi')->count();

        return response()->json([
            'success' => true,
            'data'    => $items,
            'stats'   => [
                'total_screening'        => $totalScreening,
                'total_karyawan'         => $totalKaryawan,
                'tahun_periode'          => $activeYear,
                'status_lengkap'         => $statusLengkap,
                'status_perlu_melengkapi'=> $statusPerlu,
            ],
            'metadata' => [
                'no_dokumen'      => 'FR-012/KOM.03.05/SANDIKAMI/',
                'no_revisi'       => '1.1',
                'tanggal_berlaku' => '20 Januari 2022',
                'judul_formulir'  => 'Berita Acara Screening Karyawan Outsourcing',
            ],
            'meta'    => $paginated ? [
                'current_page' => $paginated->currentPage(),
                'last_page'    => $paginated->lastPage(),
                'per_page'     => $paginated->perPage(),
                'total'        => $paginated->total(),
                'from'         => $paginated->firstItem() ?? 0,
                'to'           => $paginated->lastItem() ?? 0,
            ] : [
                'total' => count($items),
                'from'  => count($items) > 0 ? 1 : 0,
                'to'    => count($items),
            ],
        ]);
    }

    /**
     * Data Master Lookup untuk dropdown form Tambah/Edit & Filter
     */
    public function lookup()
    {
        $jabatans = Jabatan::orderBy('nama_jabatan', 'asc')->get();
        $bidangs = MasterBidang::orderBy('id_bidang', 'asc')->get();
        $items = ItemScreening::orderBy('id_item', 'asc')->get();

        $tahunOptions = ScreeningKaryawan::select('tahun')
            ->distinct()
            ->orderBy('tahun', 'desc')
            ->pluck('tahun')
            ->toArray();

        if (!in_array(2026, $tahunOptions)) {
            array_unshift($tahunOptions, 2026);
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'jabatan'            => $jabatans,
                'bidang'             => $bidangs,
                'items'              => $items,
                'tahun_options'      => $tahunOptions,
                'kesimpulan_options' => [
                    'Perlu melengkapi administrasi',
                    'Administrasi Lengkap',
                ],
            ],
        ]);
    }

    /**
     * Tampilkan detail satu data screening
     */
    public function show($id)
    {
        $screening = ScreeningKaryawan::with([
            'jabatan',
            'bidang',
            'details.item',
        ])->find($id);

        if (!$screening) {
            return response()->json([
                'success' => false,
                'message' => 'Data screening tidak ditemukan.',
            ], 404);
        }

        return response()->json([
            'success'  => true,
            'data'     => $screening,
            'metadata' => [
                'no_dokumen'      => 'FR-012/KOM.03.05/SANDIKAMI/',
                'no_revisi'       => '1.1',
                'tanggal_berlaku' => '20 Januari 2022',
            ],
        ]);
    }

    /**
     * Tambah data screening baru beserta 5 item rincian
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'nama_karyawan'      => 'required|string|max:255',
            'id_jabatan'         => 'required|exists:jabatan,id_jabatan',
            'id_bidang'          => 'required|exists:bidang,id_bidang',
            'tahun'              => 'required|integer',
            'tanggal_screening'  => 'required|date',
            'kesimpulan'         => 'required|in:Perlu melengkapi administrasi,Administrasi Lengkap',
            'catatan'            => 'nullable|string',
            'details'            => 'required|array|min:5',
            'details.*.id_item'  => 'required|exists:item_screening,id_item',
            'details.*.status'   => 'required|in:Ada,Tidak',
            'details.*.keterangan' => 'nullable|string',
        ], [
            'nama_karyawan.required'     => 'Nama Karyawan wajib diisi.',
            'id_jabatan.required'        => 'Jabatan wajib dipilih.',
            'id_bidang.required'         => 'Bidang penempatan wajib dipilih.',
            'tahun.required'             => 'Periode tahun wajib diisi.',
            'tanggal_screening.required' => 'Tanggal screening wajib diisi.',
            'kesimpulan.required'        => 'Kesimpulan akhir wajib dipilih.',
            'details.min'                => 'Seluruh 5 item pemeriksaan dokumen harus diisi statusnya (Ada/Tidak).',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
                'errors'  => $validator->errors(),
            ], 422);
        }

        try {
            DB::beginTransaction();

            $screening = ScreeningKaryawan::create([
                'nama_karyawan'     => trim($request->input('nama_karyawan')),
                'id_jabatan'        => $request->input('id_jabatan'),
                'id_bidang'         => $request->input('id_bidang'),
                'tahun'             => $request->input('tahun'),
                'tanggal_screening' => $request->input('tanggal_screening'),
                'kesimpulan'        => $request->input('kesimpulan'),
                'catatan'           => $request->input('catatan'),
            ]);

            $firstDetailId = null;
            foreach ($request->input('details', []) as $item) {
                $detail = DetailScreening::create([
                    'id_screening' => $screening->id_screening,
                    'id_item'      => $item['id_item'],
                    'status'       => $item['status'],
                    'keterangan'   => $item['keterangan'] ?? null,
                    'kesimpulan'   => $screening->kesimpulan,
                    'catatan'      => $screening->catatan,
                ]);

                if ($firstDetailId === null) {
                    $firstDetailId = $detail->id_detail;
                }
            }

            if ($firstDetailId) {
                $screening->id_detail = $firstDetailId;
                $screening->save();
            }

            DB::commit();

            $loaded = ScreeningKaryawan::with(['jabatan', 'bidang', 'details.item'])
                ->find($screening->id_screening);

            return response()->json([
                'success' => true,
                'message' => 'Data screening karyawan berhasil disimpan.',
                'data'    => $loaded,
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan server saat menyimpan data: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Perbarui data screening dan 5 item rincian
     */
    public function update(Request $request, $id)
    {
        $screening = ScreeningKaryawan::find($id);
        if (!$screening) {
            return response()->json([
                'success' => false,
                'message' => 'Data screening tidak ditemukan.',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'nama_karyawan'      => 'required|string|max:255',
            'id_jabatan'         => 'required|exists:jabatan,id_jabatan',
            'id_bidang'          => 'required|exists:bidang,id_bidang',
            'tahun'              => 'required|integer',
            'tanggal_screening'  => 'required|date',
            'kesimpulan'         => 'required|in:Perlu melengkapi administrasi,Administrasi Lengkap',
            'catatan'            => 'nullable|string',
            'details'            => 'nullable|array',
            'details.*.id_item'  => 'required_with:details|exists:item_screening,id_item',
            'details.*.status'   => 'required_with:details|in:Ada,Tidak',
            'details.*.keterangan' => 'nullable|string',
        ], [
            'nama_karyawan.required'     => 'Nama Karyawan wajib diisi.',
            'id_jabatan.required'        => 'Jabatan wajib dipilih.',
            'id_bidang.required'         => 'Bidang penempatan wajib dipilih.',
            'tahun.required'             => 'Periode tahun wajib diisi.',
            'tanggal_screening.required' => 'Tanggal screening wajib diisi.',
            'kesimpulan.required'        => 'Kesimpulan akhir wajib dipilih.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
                'errors'  => $validator->errors(),
            ], 422);
        }

        try {
            DB::beginTransaction();

            $screening->update([
                'nama_karyawan'     => trim($request->input('nama_karyawan')),
                'id_jabatan'        => $request->input('id_jabatan'),
                'id_bidang'         => $request->input('id_bidang'),
                'tahun'             => $request->input('tahun'),
                'tanggal_screening' => $request->input('tanggal_screening'),
                'kesimpulan'        => $request->input('kesimpulan'),
                'catatan'           => $request->input('catatan'),
            ]);

            if ($request->has('details')) {
                foreach ($request->input('details', []) as $item) {
                    DetailScreening::updateOrCreate(
                        [
                            'id_screening' => $screening->id_screening,
                            'id_item'      => $item['id_item'],
                        ],
                        [
                            'status'     => $item['status'],
                            'keterangan' => $item['keterangan'] ?? null,
                            'kesimpulan' => $screening->kesimpulan,
                            'catatan'    => $screening->catatan,
                        ]
                    );
                }
            }

            DB::commit();

            $loaded = ScreeningKaryawan::with(['jabatan', 'bidang', 'details.item'])
                ->find($screening->id_screening);

            return response()->json([
                'success' => true,
                'message' => 'Data screening karyawan berhasil diperbarui.',
                'data'    => $loaded,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat memperbarui data: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Hapus data screening (soft delete atau permanen)
     */
    public function destroy($id)
    {
        $screening = ScreeningKaryawan::find($id);
        if (!$screening) {
            return response()->json([
                'success' => false,
                'message' => 'Data screening tidak ditemukan.',
            ], 404);
        }

        try {
            $nama = $screening->nama_karyawan;
            $screening->details()->delete();
            $screening->delete();

            return response()->json([
                'success' => true,
                'message' => "Data screening atas nama {$nama} berhasil dihapus dari sistem.",
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus data: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * View HTML Cetak Berita Acara FR-012/KOM.03.05/SANDIKAMI
     */
    public function printHtml($id)
    {
        $screening = ScreeningKaryawan::with([
            'jabatan',
            'bidang',
            'details.item',
        ])->findOrFail($id);

        return view('print.berita_acara_screening', compact('screening'));
    }
}
