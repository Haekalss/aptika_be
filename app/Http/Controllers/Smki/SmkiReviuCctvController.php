<?php

namespace App\Http\Controllers\Smki;

use App\Http\Controllers\Controller;
use App\Models\SmkiReviuCctv;
use App\Models\SmkiReviuCctvItem;
use App\Services\SmkiReviuCctvDocxService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Formulir Reviu Monitoring dan Pemeliharaan CCTV (FR-038/KOM.03.05/SANDIKAMI).
 */
class SmkiReviuCctvController extends Controller
{
    public function __construct(private SmkiReviuCctvDocxService $docxService)
    {
    }

    /**
     * Daftar reviu + pencarian/filter + ringkasan statistik.
     */
    public function index(Request $request)
    {
        $query = SmkiReviuCctv::query()
            ->withCount('items as total_uraian')
            ->orderBy('tanggal_reviu', 'desc')
            ->orderBy('id', 'desc');

        if ($request->filled('search')) {
            $search = trim($request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('obyek_reviu', 'like', "%{$search}%")
                  ->orWhere('pelaksana_reviu', 'like', "%{$search}%")
                  ->orWhere('pengelola_nama', 'like', "%{$search}%")
                  ->orWhereHas('items', function ($i) use ($search) {
                      $i->where('uraian_reviu', 'like', "%{$search}%")
                        ->orWhere('tindak_lanjut', 'like', "%{$search}%")
                        ->orWhere('keterangan', 'like', "%{$search}%");
                  });
            });
        }

        if ($request->filled('tanggal_dari')) {
            $query->whereDate('tanggal_reviu', '>=', $request->input('tanggal_dari'));
        }
        if ($request->filled('tanggal_sampai')) {
            $query->whereDate('tanggal_reviu', '<=', $request->input('tanggal_sampai'));
        }

        $perPage = (int) $request->input('per_page', 10);
        if ($perPage === -1) {
            $items = $query->get();
            $paginated = null;
        } else {
            $paginated = $query->paginate($perPage);
            $items = $paginated->items();
        }

        // Statistik mengikuti scope bidang yang sama (BelongsToBidang) dengan daftar.
        $base = SmkiReviuCctv::query();
        $ids = (clone $base)->pluck('id');

        return response()->json([
            'success' => true,
            'data'    => $items,
            'stats'   => [
                'total_reviu'      => $ids->count(),
                'total_uraian'     => SmkiReviuCctvItem::query()->whereIn('reviu_cctv_id', $ids)->count(),
                'reviu_bulan_ini'  => (clone $base)
                    ->whereYear('tanggal_reviu', now()->year)
                    ->whereMonth('tanggal_reviu', now()->month)
                    ->count(),
                'tindak_lanjut_aktif' => SmkiReviuCctvItem::query()
                    ->whereIn('reviu_cctv_id', $ids)
                    ->whereNotNull('target_selesai')
                    ->whereDate('target_selesai', '>=', now()->toDateString())
                    ->count(),
            ],
            'meta' => $paginated ? [
                'current_page' => $paginated->currentPage(),
                'last_page'    => $paginated->lastPage(),
                'per_page'     => $paginated->perPage(),
                'total'        => $paginated->total(),
            ] : ['total' => count($items)],
        ]);
    }

    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'tanggal_reviu'     => 'required|date',
            'periode_mulai'     => 'nullable|date',
            'periode_selesai'   => 'nullable|date|after_or_equal:periode_mulai',
            'obyek_reviu'       => 'required|string|max:255',
            'pelaksana_reviu'   => 'required|string|max:255',
            'pengelola_nama'    => 'nullable|string|max:255',
            'pengelola_nip'     => 'nullable|string|max:50',
            'items'                  => 'required|array|min:1',
            'items.*.uraian_reviu'   => 'required|string',
            'items.*.tindak_lanjut'  => 'nullable|string',
            'items.*.target_selesai' => 'nullable|date',
            'items.*.keterangan'     => 'nullable|string|max:255',
        ], [
            'tanggal_reviu.required'   => 'Tanggal Reviu wajib diisi.',
            'obyek_reviu.required'     => 'Obyek Reviu wajib diisi.',
            'pelaksana_reviu.required' => 'Pelaksana Reviu wajib diisi.',
            'periode_selesai.after_or_equal' => 'Akhir periode tidak boleh sebelum awal periode.',
            'items.required'           => 'Minimal satu uraian reviu harus diisi.',
            'items.min'                => 'Minimal satu uraian reviu harus diisi.',
            'items.*.uraian_reviu.required' => 'Kolom Uraian Reviu wajib diisi.',
        ]);
    }

    private function headerData(array $validated): array
    {
        return collect($validated)->except('items')->all();
    }

    private function syncItems(SmkiReviuCctv $reviu, array $items): void
    {
        $reviu->items()->delete();
        foreach ($items as $row) {
            $tindak = isset($row['tindak_lanjut']) ? trim($row['tindak_lanjut']) : null;
            $ket = isset($row['keterangan']) ? trim($row['keterangan']) : null;
            $reviu->items()->create([
                'uraian_reviu'   => trim($row['uraian_reviu']),
                'tindak_lanjut'  => $tindak === '' ? null : $tindak,
                'target_selesai' => $row['target_selesai'] ?? null,
                'keterangan'     => $ket === '' ? null : $ket,
            ]);
        }
    }

    public function store(Request $request)
    {
        $validated = $this->validatePayload($request);

        $reviu = DB::transaction(function () use ($validated) {
            $reviu = SmkiReviuCctv::create($this->headerData($validated) + [
                'user_id'   => auth()->id(),
                'bidang_id' => auth()->user()?->bidang_id,
            ]);
            $this->syncItems($reviu, $validated['items']);
            return $reviu;
        });

        return response()->json([
            'success' => true,
            'message' => 'Formulir reviu CCTV berhasil disimpan!',
            'data'    => $reviu->load('items'),
        ], 201);
    }

    public function show($id)
    {
        $reviu = SmkiReviuCctv::with(['items', 'user:id,name,email'])->findOrFail($id);

        return response()->json(['success' => true, 'data' => $reviu]);
    }

    public function update(Request $request, $id)
    {
        $reviu = SmkiReviuCctv::findOrFail($id);
        $validated = $this->validatePayload($request);

        DB::transaction(function () use ($reviu, $validated) {
            $reviu->update($this->headerData($validated));
            $this->syncItems($reviu, $validated['items']);
        });

        return response()->json([
            'success' => true,
            'message' => 'Formulir reviu CCTV berhasil diperbarui!',
            'data'    => $reviu->load('items'),
        ]);
    }

    public function destroy($id)
    {
        $reviu = SmkiReviuCctv::findOrFail($id);
        $reviu->delete();

        return response()->json(['success' => true, 'message' => 'Formulir reviu CCTV berhasil dihapus!']);
    }

    /**
     * Unduh formulir FR-038 (.docx) dari template asli.
     */
    public function exportDocx($id)
    {
        try {
            $reviu = SmkiReviuCctv::with('items')->findOrFail($id);
            $path = $this->docxService->generateDocx($reviu);
            $name = 'FR-038_Reviu_CCTV_' . preg_replace('/[^A-Za-z0-9]+/', '_', (string) $reviu->obyek_reviu) . '_' . $reviu->id . '.docx';

            return response()->download($path, $name, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ])->deleteFileAfterSend(true);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Data formulir reviu CCTV tidak ditemukan.'], 404);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mencetak dokumen. Silakan coba beberapa saat lagi. (' . $e->getMessage() . ')',
            ], 500);
        }
    }
}
