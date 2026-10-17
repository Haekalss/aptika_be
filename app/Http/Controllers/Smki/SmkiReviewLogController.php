<?php

namespace App\Http\Controllers\Smki;

use App\Http\Controllers\Controller;
use App\Models\SmkiReviewLog;
use App\Models\SmkiReviewLogItem;
use App\Services\SmkiReviewLogDocxService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Formulir Review Log (FR-040/KOM.03.05/SANDIKAMI).
 */
class SmkiReviewLogController extends Controller
{
    public function __construct(private SmkiReviewLogDocxService $docxService)
    {
    }

    /**
     * Daftar review log + pencarian/filter + ringkasan statistik.
     */
    public function index(Request $request)
    {
        $query = SmkiReviewLog::query()
            ->withCount('items as total_log')
            ->orderBy('tanggal_review', 'desc')
            ->orderBy('id', 'desc');

        if ($request->filled('search')) {
            $search = trim($request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('obyek_review', 'like', "%{$search}%")
                  ->orWhere('pelaksana_review', 'like', "%{$search}%")
                  ->orWhere('tindak_lanjut', 'like', "%{$search}%")
                  ->orWhereHas('items', function ($i) use ($search) {
                      $i->where('user_log', 'like', "%{$search}%")
                        ->orWhere('ip_address', 'like', "%{$search}%");
                  });
            });
        }

        if ($request->filled('tanggal_dari')) {
            $query->whereDate('tanggal_review', '>=', $request->input('tanggal_dari'));
        }
        if ($request->filled('tanggal_sampai')) {
            $query->whereDate('tanggal_review', '<=', $request->input('tanggal_sampai'));
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
        $base = SmkiReviewLog::query();
        $ids = (clone $base)->pluck('id');

        return response()->json([
            'success' => true,
            'data'    => $items,
            'stats'   => [
                'total_review'      => $ids->count(),
                'total_log'         => SmkiReviewLogItem::query()->whereIn('review_log_id', $ids)->count(),
                'review_bulan_ini'  => (clone $base)
                    ->whereYear('tanggal_review', now()->year)
                    ->whereMonth('tanggal_review', now()->month)
                    ->count(),
                'ada_tindak_lanjut' => (clone $base)
                    ->whereNotNull('tindak_lanjut')
                    ->where('tindak_lanjut', '!=', '')
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
            'tanggal_review'    => 'required|date',
            'periode_mulai'     => 'nullable|date',
            'periode_selesai'   => 'nullable|date|after_or_equal:periode_mulai',
            'obyek_review'      => 'required|string|max:255',
            'pelaksana_review'  => 'required|string|max:255',
            'tindak_lanjut'     => 'nullable|string',
            'dibuat_oleh_nama'  => 'nullable|string|max:255',
            'dibuat_oleh_nip'   => 'nullable|string|max:50',
            'mengetahui_nama'   => 'nullable|string|max:255',
            'mengetahui_nip'    => 'nullable|string|max:50',
            'ditandatangani_di' => 'nullable|string|max:255',
            'tanggal_ttd'       => 'nullable|date',
            'items'             => 'nullable|array',
            'items.*.user_log'   => 'required|string|max:255',
            'items.*.ip_address' => 'required|string|max:45',
            'items.*.tanggal'    => 'required|date',
            'items.*.waktu'      => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/'],
            'items.*.durasi'     => 'required|string|max:50',
        ], [
            'tanggal_review.required'     => 'Tanggal Review wajib diisi.',
            'obyek_review.required'       => 'Obyek Review wajib diisi.',
            'pelaksana_review.required'   => 'Pelaksana Review wajib diisi.',
            'periode_selesai.after_or_equal' => 'Akhir periode tidak boleh sebelum awal periode.',
            'items.*.user_log.required'   => 'Kolom User pada log wajib diisi.',
            'items.*.ip_address.required' => 'Kolom IP pada log wajib diisi.',
            'items.*.tanggal.required'    => 'Kolom Tanggal pada log wajib diisi.',
            'items.*.waktu.required'      => 'Kolom Waktu pada log wajib diisi.',
            'items.*.waktu.regex'         => 'Format Waktu harus HH:MM.',
            'items.*.durasi.required'     => 'Kolom Durasi pada log wajib diisi.',
        ]);
    }

    private function headerData(array $validated): array
    {
        return collect($validated)->except('items')->all();
    }

    private function syncItems(SmkiReviewLog $review, array $items): void
    {
        $review->items()->delete();
        foreach ($items as $row) {
            $review->items()->create([
                'user_log'   => trim($row['user_log']),
                'ip_address' => trim($row['ip_address']),
                'tanggal'    => $row['tanggal'],
                'waktu'      => $row['waktu'],
                'durasi'     => trim($row['durasi']),
            ]);
        }
    }

    public function store(Request $request)
    {
        $validated = $this->validatePayload($request);

        $review = DB::transaction(function () use ($validated) {
            $review = SmkiReviewLog::create($this->headerData($validated) + [
                'user_id'   => auth()->id(),
                'bidang_id' => auth()->user()?->bidang_id,
            ]);
            $this->syncItems($review, $validated['items'] ?? []);
            return $review;
        });

        return response()->json([
            'success' => true,
            'message' => 'Formulir review log berhasil disimpan!',
            'data'    => $review->load('items'),
        ], 201);
    }

    public function show($id)
    {
        $review = SmkiReviewLog::with(['items', 'user:id,name,email'])->findOrFail($id);

        return response()->json(['success' => true, 'data' => $review]);
    }

    public function update(Request $request, $id)
    {
        $review = SmkiReviewLog::findOrFail($id);
        $validated = $this->validatePayload($request);

        DB::transaction(function () use ($review, $validated) {
            $review->update($this->headerData($validated));
            $this->syncItems($review, $validated['items'] ?? []);
        });

        return response()->json([
            'success' => true,
            'message' => 'Formulir review log berhasil diperbarui!',
            'data'    => $review->load('items'),
        ]);
    }

    public function destroy($id)
    {
        $review = SmkiReviewLog::findOrFail($id);
        $review->delete();

        return response()->json(['success' => true, 'message' => 'Formulir review log berhasil dihapus!']);
    }

    /**
     * Unduh formulir FR-040 (.docx) dari template asli.
     */
    public function exportDocx($id)
    {
        try {
            $log = SmkiReviewLog::with('items')->findOrFail($id);
            $path = $this->docxService->generateDocx($log);
            $name = 'FR-040_Review_Log_' . $log->tanggal_review->format('Y-m-d') . '.docx';

            return response()->download($path, $name, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ])->deleteFileAfterSend(true);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Data review log tidak ditemukan.'], 404);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mencetak dokumen. Silakan coba beberapa saat lagi. (' . $e->getMessage() . ')',
            ], 500);
        }
    }
}
