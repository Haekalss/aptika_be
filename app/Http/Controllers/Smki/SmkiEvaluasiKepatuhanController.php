<?php

namespace App\Http\Controllers\Smki;

use App\Http\Controllers\Controller;
use App\Models\SmkiEvaluasiKepatuhan;
use App\Models\SmkiEvaluasiKepatuhanItem;
use App\Services\SmkiEvaluasiKepatuhanDocxService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SmkiEvaluasiKepatuhanController extends Controller
{
    protected SmkiEvaluasiKepatuhanDocxService $docxService;

    public function __construct(SmkiEvaluasiKepatuhanDocxService $docxService)
    {
        $this->docxService = $docxService;
    }

    /**
     * Daftar evaluasi + pencarian/filter + ringkasan statistik.
     */
    public function index(Request $request)
    {
        $query = SmkiEvaluasiKepatuhan::query()
            ->withCount([
                'items as total_dokumen',
                'items as patuh_count'        => fn($q) => $q->where('evaluasi_status', 'Patuh'),
                'items as tidak_patuh_count'  => fn($q) => $q->where('evaluasi_status', 'Tidak Patuh'),
                'items as dalam_proses_count' => fn($q) => $q->where('evaluasi_status', 'Dalam Proses'),
            ])
            ->orderBy('tanggal_evaluasi', 'desc')
            ->orderBy('id', 'desc');

        if ($request->filled('search')) {
            $search = trim($request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('ditandatangani_di', 'like', "%{$search}%")
                  ->orWhereHas('items', function ($i) use ($search) {
                      $i->where('dokumen_kepatuhan', 'like', "%{$search}%")
                        ->orWhere('deskripsi', 'like', "%{$search}%");
                  });
            });
        }

        if ($request->filled('status')) {
            $status = $request->input('status');
            $query->whereHas('items', fn($i) => $i->where('evaluasi_status', $status));
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
        $evaluasiIds = SmkiEvaluasiKepatuhan::query()->pluck('id');
        $itemBase = SmkiEvaluasiKepatuhanItem::query()->whereIn('evaluasi_kepatuhan_id', $evaluasiIds);

        return response()->json([
            'success' => true,
            'data'    => $items,
            'stats'   => [
                'total_evaluasi' => $evaluasiIds->count(),
                'patuh'          => (clone $itemBase)->where('evaluasi_status', 'Patuh')->count(),
                'tidak_patuh'    => (clone $itemBase)->where('evaluasi_status', 'Tidak Patuh')->count(),
                'dalam_proses'   => (clone $itemBase)->where('evaluasi_status', 'Dalam Proses')->count(),
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
            'tanggal_evaluasi'            => 'required|date',
            'ditandatangani_di'           => 'nullable|string|max:255',
            'tanggal_ttd'                 => 'nullable|date',
            'items'                       => 'required|array|min:1',
            'items.*.dokumen_kepatuhan'   => 'required|string|max:255',
            'items.*.deskripsi'           => 'required|string',
            'items.*.evaluasi_status'     => 'required|in:Patuh,Tidak Patuh,Dalam Proses',
        ], [
            'items.required'                     => 'Minimal satu dokumen kepatuhan harus diisi.',
            'items.min'                          => 'Minimal satu dokumen kepatuhan harus diisi.',
            'items.*.dokumen_kepatuhan.required' => 'Dokumen Kepatuhan wajib diisi.',
            'items.*.deskripsi.required'         => 'Deskripsi wajib diisi.',
            'items.*.evaluasi_status.required'   => 'Evaluasi/Status wajib dipilih.',
        ]);
    }

    private function syncItems(SmkiEvaluasiKepatuhan $evaluasi, array $items): void
    {
        $evaluasi->items()->delete();
        foreach ($items as $row) {
            $evaluasi->items()->create([
                'dokumen_kepatuhan' => trim($row['dokumen_kepatuhan']),
                'deskripsi'         => trim($row['deskripsi']),
                'evaluasi_status'   => $row['evaluasi_status'],
            ]);
        }
    }

    public function store(Request $request)
    {
        $validated = $this->validatePayload($request);

        $evaluasi = DB::transaction(function () use ($validated) {
            $evaluasi = SmkiEvaluasiKepatuhan::create([
                'tanggal_evaluasi'  => $validated['tanggal_evaluasi'],
                'ditandatangani_di' => $validated['ditandatangani_di'] ?? null,
                'tanggal_ttd'       => $validated['tanggal_ttd'] ?? null,
                'user_id'           => auth()->id(),
                'bidang_id'         => auth()->user()?->bidang_id,
            ]);
            $this->syncItems($evaluasi, $validated['items']);
            return $evaluasi;
        });

        return response()->json([
            'success' => true,
            'message' => 'Data evaluasi kepatuhan berhasil disimpan!',
            'data'    => $evaluasi->load('items'),
        ], 201);
    }

    public function show($id)
    {
        $evaluasi = SmkiEvaluasiKepatuhan::with(['items', 'user:id,name,email'])->findOrFail($id);

        return response()->json(['success' => true, 'data' => $evaluasi]);
    }

    public function update(Request $request, $id)
    {
        $evaluasi = SmkiEvaluasiKepatuhan::findOrFail($id);
        $validated = $this->validatePayload($request);

        DB::transaction(function () use ($evaluasi, $validated) {
            $evaluasi->update([
                'tanggal_evaluasi'  => $validated['tanggal_evaluasi'],
                'ditandatangani_di' => $validated['ditandatangani_di'] ?? null,
                'tanggal_ttd'       => $validated['tanggal_ttd'] ?? null,
            ]);
            $this->syncItems($evaluasi, $validated['items']);
        });

        return response()->json([
            'success' => true,
            'message' => 'Data evaluasi kepatuhan berhasil diperbarui!',
            'data'    => $evaluasi->load('items'),
        ]);
    }

    public function destroy($id)
    {
        $evaluasi = SmkiEvaluasiKepatuhan::findOrFail($id);
        $evaluasi->delete();

        return response()->json(['success' => true, 'message' => 'Data evaluasi kepatuhan berhasil dihapus!']);
    }

    /**
     * Ekspor 1 sesi evaluasi ke template resmi FR-044.
     */
    public function exportDocx(Request $request, $id)
    {
        try {
            $evaluasi = SmkiEvaluasiKepatuhan::with('items')->findOrFail($id);

            $options = [
                'no_dokumen'      => $request->input('no_dokumen'),
                'no_revisi'       => $request->input('no_revisi'),
                'tanggal_berlaku' => $request->input('tanggal_berlaku'),
            ];

            $docxPath = $this->docxService->generateDocx($evaluasi, $options);
            $fileName = 'FR-044_Evaluasi_Kepatuhan_' . $evaluasi->tanggal_evaluasi->format('Y-m-d') . '.docx';

            return response()->download($docxPath, $fileName, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ])->deleteFileAfterSend(true);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Data evaluasi tidak ditemukan.'], 404);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mencetak dokumen. Silakan coba beberapa saat lagi. (' . $e->getMessage() . ')',
            ], 500);
        }
    }
}
