<?php

namespace App\Http\Controllers\Smki;

use App\Http\Controllers\Controller;
use App\Models\SmkiPemeriksaanCctv;
use App\Models\SmkiPemeriksaanCctvItem;
use App\Services\SmkiPemeriksaanCctvDocxService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SmkiPemeriksaanCctvController extends Controller
{
    protected SmkiPemeriksaanCctvDocxService $docxService;

    public function __construct(SmkiPemeriksaanCctvDocxService $docxService)
    {
        $this->docxService = $docxService;
    }

    /**
     * Syarat "CCTV bermasalah": mati, rekaman buruk, atau ada obstacle.
     */
    private function applyProblemCondition($query)
    {
        return $query->where(function ($w) {
            $w->where('fungsi', 'Mati')
              ->orWhere('rekaman', 'Buruk')
              ->orWhere('obstacle', 'Ada');
        });
    }

    public function index(Request $request)
    {
        $query = SmkiPemeriksaanCctv::query()
            ->withCount([
                'items as total_cctv',
                'items as bermasalah_count' => fn($q) => $this->applyProblemCondition($q),
            ])
            ->orderBy('tanggal_pemeriksaan', 'desc')
            ->orderBy('id', 'desc');

        if ($request->filled('search')) {
            $search = trim($request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('perangkat_perekaman', 'like', "%{$search}%")
                  ->orWhere('nama_pemeriksa', 'like', "%{$search}%")
                  ->orWhereHas('items', function ($i) use ($search) {
                      $i->where('kode_cctv', 'like', "%{$search}%")
                        ->orWhere('nama_cctv', 'like', "%{$search}%")
                        ->orWhere('lokasi', 'like', "%{$search}%");
                  });
            });
        }

        if ($request->filled('lingkup')) {
            $query->where('lingkup', $request->input('lingkup'));
        }

        if ($request->input('kondisi') === 'bermasalah') {
            $query->whereHas('items', fn($i) => $this->applyProblemCondition($i));
        } elseif ($request->input('kondisi') === 'normal') {
            $query->whereDoesntHave('items', fn($i) => $this->applyProblemCondition($i));
        }

        if ($request->filled('tanggal_dari')) {
            $query->whereDate('tanggal_pemeriksaan', '>=', $request->input('tanggal_dari'));
        }
        if ($request->filled('tanggal_sampai')) {
            $query->whereDate('tanggal_pemeriksaan', '<=', $request->input('tanggal_sampai'));
        }

        $perPage = (int) $request->input('per_page', 10);
        if ($perPage === -1) {
            $items = $query->get();
            $paginated = null;
        } else {
            $paginated = $query->paginate($perPage);
            $items = $paginated->items();
        }

        // Statistik mengikuti scope bidang yang sama dengan daftar (BelongsToBidang).
        $ids = SmkiPemeriksaanCctv::query()->pluck('id');
        $itemBase = SmkiPemeriksaanCctvItem::query()->whereIn('pemeriksaan_cctv_id', $ids);

        return response()->json([
            'success' => true,
            'data'    => $items,
            'stats'   => [
                'total_pemeriksaan' => $ids->count(),
                'total_cctv'        => (clone $itemBase)->count(),
                'cctv_berfungsi'    => (clone $itemBase)->where('fungsi', 'Berfungsi')->count(),
                'cctv_bermasalah'   => $this->applyProblemCondition(clone $itemBase)->count(),
            ],
            'meta' => $paginated ? [
                'current_page' => $paginated->currentPage(),
                'last_page'    => $paginated->lastPage(),
                'per_page'     => $paginated->perPage(),
                'total'        => $paginated->total(),
            ] : ['total' => count($items)],
        ]);
    }

    /**
     * Pemeriksaan terakhir (untuk fitur "Salin dari pemeriksaan sebelumnya").
     * Hanya mengembalikan data identitas CCTV, bukan hasil pemeriksaannya.
     */
    public function terakhir(Request $request)
    {
        $query = SmkiPemeriksaanCctv::with('items')
            ->orderBy('tanggal_pemeriksaan', 'desc')
            ->orderBy('id', 'desc');

        if ($request->filled('lingkup')) {
            $query->where('lingkup', $request->input('lingkup'));
        }

        $last = $query->first();
        if (!$last) {
            return response()->json(['success' => true, 'data' => null]);
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'lingkup'             => $last->lingkup,
                'perangkat_perekaman' => $last->perangkat_perekaman,
                'nama_pemeriksa'      => $last->nama_pemeriksa,
                'tanggal_pemeriksaan' => $last->tanggal_pemeriksaan->format('Y-m-d'),
                'items'               => $last->items->map(fn($i) => [
                    'kode_cctv' => $i->kode_cctv,
                    'nama_cctv' => $i->nama_cctv,
                    'lokasi'    => $i->lokasi,
                    'petugas'   => $i->petugas,
                ])->values(),
            ],
        ]);
    }

    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'lingkup'                  => 'required|in:Data Center,Gedung',
            'perangkat_perekaman'      => 'required|string|max:255',
            'tanggal_pemeriksaan'      => 'required|date',
            'kesesuaian_waktu'         => 'required|in:Sesuai,Tidak Sesuai',
            'nama_pemeriksa'           => 'nullable|string|max:255',
            'items'                    => 'required|array|min:1',
            'items.*.kode_cctv'        => 'required|string|max:100',
            'items.*.nama_cctv'        => 'required|string|max:255',
            'items.*.lokasi'           => 'required|string|max:255',
            'items.*.fungsi'           => 'required|in:Berfungsi,Mati',
            'items.*.rekaman'          => 'required|in:Baik,Buruk',
            'items.*.obstacle'         => 'required|in:Ada,Tidak Ada',
            'items.*.keterangan'       => 'nullable|string',
            'items.*.tindak_lanjut'    => 'nullable|string',
            'items.*.petugas'          => 'nullable|string|max:255',
        ], [
            'items.required'               => 'Minimal satu CCTV harus diisi.',
            'items.min'                    => 'Minimal satu CCTV harus diisi.',
            'items.*.kode_cctv.required'   => 'Kode CCTV wajib diisi.',
            'items.*.nama_cctv.required'   => 'Nama CCTV wajib diisi.',
            'items.*.lokasi.required'      => 'Lokasi CCTV wajib diisi.',
            'items.*.fungsi.required'      => 'Fungsi wajib dipilih.',
            'items.*.rekaman.required'     => 'Rekaman wajib dipilih.',
            'items.*.obstacle.required'    => 'Obstacle wajib dipilih.',
        ]);
    }

    private function syncItems(SmkiPemeriksaanCctv $pemeriksaan, array $items): void
    {
        $pemeriksaan->items()->delete();
        foreach ($items as $row) {
            $pemeriksaan->items()->create([
                'kode_cctv'     => trim($row['kode_cctv']),
                'nama_cctv'     => trim($row['nama_cctv']),
                'lokasi'        => trim($row['lokasi']),
                'fungsi'        => $row['fungsi'],
                'rekaman'       => $row['rekaman'],
                'obstacle'      => $row['obstacle'],
                'keterangan'    => isset($row['keterangan']) ? trim($row['keterangan']) ?: null : null,
                'tindak_lanjut' => isset($row['tindak_lanjut']) ? trim($row['tindak_lanjut']) ?: null : null,
                'petugas'       => isset($row['petugas']) ? trim($row['petugas']) ?: null : null,
            ]);
        }
    }

    public function store(Request $request)
    {
        $v = $this->validatePayload($request);

        $pemeriksaan = DB::transaction(function () use ($v) {
            $p = SmkiPemeriksaanCctv::create([
                'lingkup'             => $v['lingkup'],
                'perangkat_perekaman' => trim($v['perangkat_perekaman']),
                'tanggal_pemeriksaan' => $v['tanggal_pemeriksaan'],
                'kesesuaian_waktu'    => $v['kesesuaian_waktu'],
                'nama_pemeriksa'      => $v['nama_pemeriksa'] ?? null,
                'user_id'             => auth()->id(),
                'bidang_id'           => auth()->user()?->bidang_id,
            ]);
            $this->syncItems($p, $v['items']);
            return $p;
        });

        return response()->json([
            'success' => true,
            'message' => 'Data pemeriksaan CCTV berhasil disimpan!',
            'data'    => $pemeriksaan->load('items'),
        ], 201);
    }

    public function show($id)
    {
        $pemeriksaan = SmkiPemeriksaanCctv::with(['items', 'user:id,name,email'])->findOrFail($id);

        return response()->json(['success' => true, 'data' => $pemeriksaan]);
    }

    public function update(Request $request, $id)
    {
        $pemeriksaan = SmkiPemeriksaanCctv::findOrFail($id);
        $v = $this->validatePayload($request);

        DB::transaction(function () use ($pemeriksaan, $v) {
            $pemeriksaan->update([
                'lingkup'             => $v['lingkup'],
                'perangkat_perekaman' => trim($v['perangkat_perekaman']),
                'tanggal_pemeriksaan' => $v['tanggal_pemeriksaan'],
                'kesesuaian_waktu'    => $v['kesesuaian_waktu'],
                'nama_pemeriksa'      => $v['nama_pemeriksa'] ?? null,
            ]);
            $this->syncItems($pemeriksaan, $v['items']);
        });

        return response()->json([
            'success' => true,
            'message' => 'Data pemeriksaan CCTV berhasil diperbarui!',
            'data'    => $pemeriksaan->load('items'),
        ]);
    }

    public function destroy($id)
    {
        SmkiPemeriksaanCctv::findOrFail($id)->delete();

        return response()->json(['success' => true, 'message' => 'Data pemeriksaan CCTV berhasil dihapus!']);
    }

    public function exportDocx(Request $request, $id)
    {
        try {
            $pemeriksaan = SmkiPemeriksaanCctv::with('items')->findOrFail($id);

            $options = [
                'no_dokumen'      => $request->input('no_dokumen'),
                'no_revisi'       => $request->input('no_revisi'),
                'tanggal_berlaku' => $request->input('tanggal_berlaku'),
            ];

            $path = $this->docxService->generateDocx($pemeriksaan, $options);
            $name = 'FR-037_Checklist_CCTV_' . $pemeriksaan->tanggal_pemeriksaan->format('Y-m-d') . '.docx';

            return response()->download($path, $name, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ])->deleteFileAfterSend(true);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Data pemeriksaan tidak ditemukan.'], 404);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mencetak dokumen. Silakan coba beberapa saat lagi. (' . $e->getMessage() . ')',
            ], 500);
        }
    }
}
