<?php

namespace App\Http\Controllers\Smki;

use App\Http\Controllers\Controller;
use App\Models\SmkiDrpEvaluasi;
use App\Models\SmkiDrpEvaluasiProses;
use App\Models\SmkiDrpEvaluasiTim;
use App\Models\SmkiDrpEvaluasiWaktu;
use App\Services\SmkiDrpEvaluasiDocxService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Formulir Laporan Evaluasi dan Pengujian DRP (FR-039/KOM.03.05/SANDIKAMI).
 */
class SmkiDrpEvaluasiController extends Controller
{
    private const RELATIONS = ['waktu', 'proses', 'tim'];

    public function __construct(private SmkiDrpEvaluasiDocxService $docxService)
    {
    }

    /**
     * Daftar laporan + pencarian/filter + ringkasan statistik.
     */
    public function index(Request $request)
    {
        $query = SmkiDrpEvaluasi::query()
            ->withCount(['waktu as total_sistem', 'proses as total_proses', 'tim as total_tim'])
            ->orderBy('tanggal_pengujian', 'desc')
            ->orderBy('id', 'desc');

        if ($request->filled('search')) {
            $search = trim($request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('tujuan', 'like', "%{$search}%")
                  ->orWhere('cakupan', 'like', "%{$search}%")
                  ->orWhere('tipe_pengujian', 'like', "%{$search}%")
                  ->orWhere('asumsi_bencana', 'like', "%{$search}%")
                  ->orWhereHas('waktu', fn($i) => $i->where('sistem_ti', 'like', "%{$search}%"))
                  ->orWhereHas('proses', function ($i) use ($search) {
                      $i->where('proses', 'like', "%{$search}%")
                        ->orWhere('pic', 'like', "%{$search}%");
                  })
                  ->orWhereHas('tim', fn($i) => $i->where('peran', 'like', "%{$search}%"));
            });
        }

        if ($request->filled('tanggal_dari')) {
            $query->whereDate('tanggal_pengujian', '>=', $request->input('tanggal_dari'));
        }
        if ($request->filled('tanggal_sampai')) {
            $query->whereDate('tanggal_pengujian', '<=', $request->input('tanggal_sampai'));
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
        $base = SmkiDrpEvaluasi::query();
        $ids = (clone $base)->pluck('id');

        return response()->json([
            'success' => true,
            'data'    => $items,
            'stats'   => [
                'total_evaluasi'     => $ids->count(),
                'evaluasi_bulan_ini' => (clone $base)
                    ->whereYear('tanggal_pengujian', now()->year)
                    ->whereMonth('tanggal_pengujian', now()->month)
                    ->count(),
                'total_sistem'       => SmkiDrpEvaluasiWaktu::query()->whereIn('drp_evaluasi_id', $ids)->count(),
                'total_rekomendasi'  => SmkiDrpEvaluasiProses::query()
                    ->whereIn('drp_evaluasi_id', $ids)
                    ->whereNotNull('rekomendasi')
                    ->where('rekomendasi', '!=', '')
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
        $timeRule = ['nullable', 'regex:/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/'];

        return $request->validate([
            'tanggal_pengujian'  => 'required|date',
            'waktu_mulai'        => $timeRule,
            'waktu_selesai'      => $timeRule,
            'tujuan'             => 'required|string',
            'cakupan'            => 'required|string',
            'tipe_pengujian'     => 'required|string',
            'asumsi_bencana'     => 'nullable|string',
            'asumsi_sumber_daya' => 'nullable|string',
            'checklist'          => 'nullable|array',
            'checklist.*'        => 'nullable|boolean',
            'lampiran'           => 'nullable|string',
            'dibuat_oleh_nama'       => 'nullable|string|max:255',
            'dibuat_oleh_tanggal'    => 'nullable|date',
            'diperiksa_oleh_nama'    => 'nullable|string|max:255',
            'diperiksa_oleh_tanggal' => 'nullable|date',
            'disetujui_oleh_nama'    => 'nullable|string|max:255',
            'disetujui_oleh_tanggal' => 'nullable|date',

            'waktu'                  => 'nullable|array',
            'waktu.*.sistem_ti'      => 'required|string|max:255',
            'waktu.*.rto_ideal'      => 'nullable|string|max:255',
            'waktu.*.pencapaian_rto' => 'nullable|string|max:255',
            'waktu.*.kendala'        => 'nullable|string',

            'proses'                 => 'nullable|array',
            'proses.*.proses'        => 'required|string|max:255',
            'proses.*.aktivitas'     => 'nullable|string',
            'proses.*.hasil_evaluasi' => 'nullable|string',
            'proses.*.rekomendasi'   => 'nullable|string',
            'proses.*.pic'           => 'nullable|string|max:255',

            'tim'                    => 'nullable|array',
            'tim.*.peran'            => 'required|string|max:255',
            'tim.*.tanggung_jawab'   => 'nullable|string',
            'tim.*.evaluasi'         => 'nullable|string',
            'tim.*.rekomendasi'      => 'nullable|string',
        ], [
            'tanggal_pengujian.required' => 'Tanggal Pengujian wajib diisi.',
            'waktu_mulai.regex'          => 'Format Waktu Mulai harus HH:MM.',
            'waktu_selesai.regex'        => 'Format Waktu Selesai harus HH:MM.',
            'tujuan.required'            => 'Tujuan Pengujian wajib diisi.',
            'cakupan.required'           => 'Cakupan Pengujian wajib diisi.',
            'tipe_pengujian.required'    => 'Tipe Pengujian wajib diisi.',
            'waktu.*.sistem_ti.required' => 'Kolom Sistem TI wajib diisi.',
            'proses.*.proses.required'   => 'Kolom Proses wajib diisi.',
            'tim.*.peran.required'       => 'Kolom Peran wajib diisi.',
        ]);
    }

    private function headerData(array $validated): array
    {
        $data = collect($validated)->except(self::RELATIONS)->all();
        // Checklist: hanya kunci kriteria yang dikenal, nilai boolean.
        $checklist = [];
        foreach (array_keys(SmkiDrpEvaluasi::CHECKLIST) as $key) {
            $checklist[$key] = (bool) ($validated['checklist'][$key] ?? false);
        }
        $data['checklist'] = $checklist;

        return $data;
    }

    private function clean($value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function syncChildren(SmkiDrpEvaluasi $evaluasi, array $validated): void
    {
        $evaluasi->waktu()->delete();
        foreach ($validated['waktu'] ?? [] as $row) {
            $evaluasi->waktu()->create([
                'sistem_ti'      => trim($row['sistem_ti']),
                'rto_ideal'      => $this->clean($row['rto_ideal'] ?? null),
                'pencapaian_rto' => $this->clean($row['pencapaian_rto'] ?? null),
                'kendala'        => $this->clean($row['kendala'] ?? null),
            ]);
        }

        $evaluasi->proses()->delete();
        foreach ($validated['proses'] ?? [] as $row) {
            $evaluasi->proses()->create([
                'proses'         => trim($row['proses']),
                'aktivitas'      => $this->clean($row['aktivitas'] ?? null),
                'hasil_evaluasi' => $this->clean($row['hasil_evaluasi'] ?? null),
                'rekomendasi'    => $this->clean($row['rekomendasi'] ?? null),
                'pic'            => $this->clean($row['pic'] ?? null),
            ]);
        }

        $evaluasi->tim()->delete();
        foreach ($validated['tim'] ?? [] as $row) {
            $evaluasi->tim()->create([
                'peran'          => trim($row['peran']),
                'tanggung_jawab' => $this->clean($row['tanggung_jawab'] ?? null),
                'evaluasi'       => $this->clean($row['evaluasi'] ?? null),
                'rekomendasi'    => $this->clean($row['rekomendasi'] ?? null),
            ]);
        }
    }

    public function store(Request $request)
    {
        $validated = $this->validatePayload($request);

        $evaluasi = DB::transaction(function () use ($validated) {
            $evaluasi = SmkiDrpEvaluasi::create($this->headerData($validated) + [
                'user_id'   => auth()->id(),
                'bidang_id' => auth()->user()?->bidang_id,
            ]);
            $this->syncChildren($evaluasi, $validated);
            return $evaluasi;
        });

        return response()->json([
            'success' => true,
            'message' => 'Laporan evaluasi pengujian DRP berhasil disimpan!',
            'data'    => $evaluasi->load(self::RELATIONS),
        ], 201);
    }

    public function show($id)
    {
        $evaluasi = SmkiDrpEvaluasi::with([...self::RELATIONS, 'user:id,name,email'])->findOrFail($id);

        return response()->json([
            'success'   => true,
            'data'      => $evaluasi,
            'checklist_kriteria' => collect(SmkiDrpEvaluasi::CHECKLIST)
                ->map(fn($v, $k) => ['key' => $k, 'kategori' => $v[0], 'kriteria' => $v[1]])
                ->values(),
        ]);
    }

    public function update(Request $request, $id)
    {
        $evaluasi = SmkiDrpEvaluasi::findOrFail($id);
        $validated = $this->validatePayload($request);

        DB::transaction(function () use ($evaluasi, $validated) {
            $evaluasi->update($this->headerData($validated));
            $this->syncChildren($evaluasi, $validated);
        });

        return response()->json([
            'success' => true,
            'message' => 'Laporan evaluasi pengujian DRP berhasil diperbarui!',
            'data'    => $evaluasi->load(self::RELATIONS),
        ]);
    }

    public function destroy($id)
    {
        $evaluasi = SmkiDrpEvaluasi::findOrFail($id);
        $evaluasi->delete();

        return response()->json(['success' => true, 'message' => 'Laporan evaluasi pengujian DRP berhasil dihapus!']);
    }

    /**
     * Unduh formulir FR-039 (.docx) dari template asli.
     */
    public function exportDocx($id)
    {
        try {
            $evaluasi = SmkiDrpEvaluasi::with(self::RELATIONS)->findOrFail($id);
            $path = $this->docxService->generateDocx($evaluasi);
            $name = 'FR-039_Evaluasi_Pengujian_DRP_' . $evaluasi->tanggal_pengujian?->format('Ymd') . '_' . $evaluasi->id . '.docx';

            return response()->download($path, $name, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ])->deleteFileAfterSend(true);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Data laporan evaluasi DRP tidak ditemukan.'], 404);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mencetak dokumen. Silakan coba beberapa saat lagi. (' . $e->getMessage() . ')',
            ], 500);
        }
    }
}
