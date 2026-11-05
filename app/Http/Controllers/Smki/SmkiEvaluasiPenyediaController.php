<?php

namespace App\Http\Controllers\Smki;

use App\Http\Controllers\Controller;
use App\Http\Requests\Smki\EvaluasiPenyedia\AjukanEvaluasiPenyediaRequest;
use App\Http\Requests\Smki\EvaluasiPenyedia\DeleteEvaluasiPenyediaRequest;
use App\Http\Requests\Smki\EvaluasiPenyedia\HitungEvaluasiPenyediaRequest;
use App\Http\Requests\Smki\EvaluasiPenyedia\KembalikanEvaluasiPenyediaRequest;
use App\Http\Requests\Smki\EvaluasiPenyedia\SetujuiEvaluasiPenyediaRequest;
use App\Http\Requests\Smki\EvaluasiPenyedia\StoreEvaluasiPenyediaRequest;
use App\Http\Requests\Smki\EvaluasiPenyedia\UpdateIdentifikasiEvaluasiPenyediaRequest;
use App\Http\Requests\Smki\EvaluasiPenyedia\UpdatePenilaianEvaluasiPenyediaRequest;
use App\Http\Resources\Smki\SmkiEvaluasiPenyediaResource;
use App\Models\SmkiEvaluasiKriteria;
use App\Models\SmkiEvaluasiPenyedia;
use App\Models\SmkiEvaluasiPenyediaLog;
use App\Models\SmkiRekomendasiEvaluasi;
use App\Models\SmkiSkalaNilai;
use App\Models\User;
use App\Services\Smki\EvaluasiPenyediaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class SmkiEvaluasiPenyediaController extends Controller
{
    public function __construct(private EvaluasiPenyediaService $service) {}

    public function index(Request $request)
    {
        Gate::authorize('viewAny', SmkiEvaluasiPenyedia::class);
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'sifat' => ['nullable', Rule::in(array_keys(SmkiEvaluasiPenyedia::SIFAT))],
            'status' => ['nullable', Rule::in(SmkiEvaluasiPenyedia::STATUSES)],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = SmkiEvaluasiPenyedia::visibleTo($request->user());
        if (filled($filters['q'] ?? null)) {
            $needle = $filters['q'];
            $query->where(function ($query) use ($needle) {
                $query->whereHas('penyedia', fn ($q) => $q->where('nama_penyedia', 'like', '%' . $needle . '%'))
                    ->orWhere('no_kontrak', 'like', '%' . $needle . '%');
            });
        }
        if (filled($filters['sifat'] ?? null)) {
            $query->where('sifat_pekerjaan', $filters['sifat']);
        }
        if (filled($filters['status'] ?? null)) {
            $query->where('status', $filters['status']);
        }

        $summary = [
            'total' => (clone $query)->count(),
            'draft' => (clone $query)->where('status', 'draft')->count(),
            'diajukan' => (clone $query)->where('status', 'diajukan')->count(),
            'revisi' => (clone $query)->where('status', 'revisi')->count(),
            'selesai' => (clone $query)->where('status', 'selesai')->count(),
        ];
        $records = $query->latest('id')->paginate($filters['per_page'] ?? 10);

        return response()->json([
            'data' => SmkiEvaluasiPenyediaResource::collection($records->items())->resolve($request),
            'summary' => $summary,
            'meta' => [
                'current_page' => $records->currentPage(),
                'last_page' => $records->lastPage(),
                'total' => $records->total(),
                'per_page' => $records->perPage(),
            ],
        ]);
    }

    public function master()
    {
        Gate::authorize('viewAny', SmkiEvaluasiPenyedia::class);

        return response()->json(['data' => [
            'kriteria' => SmkiEvaluasiKriteria::ordered()->get(['id', 'urutan', 'nama_kriteria']),
            'skala' => SmkiSkalaNilai::orderBy('id')->get(['id', 'label', 'skor']),
            'rekomendasi' => SmkiRekomendasiEvaluasi::orderBy('nilai_min')->get(['id', 'kategori', 'nilai_min', 'nilai_max', 'keterangan']),
            'template' => SmkiEvaluasiPenyedia::TEMPLATE,
        ]]);
    }

    public function admins()
    {
        Gate::authorize('viewAny', SmkiEvaluasiPenyedia::class);

        $users = User::where('role', 'admin')->where('is_active', 1)->orderBy('name')->get(['id', 'name', 'position']);

        return response()->json(['data' => $users->map(fn ($user) => [
            'id' => (int) $user->id,
            'name' => $user->name,
            'position' => $user->position,
        ])->values()]);
    }

    public function store(StoreEvaluasiPenyediaRequest $request)
    {
        Gate::authorize('create', SmkiEvaluasiPenyedia::class);
        $record = $this->service->create($request->validated(), $request->user());

        return (new SmkiEvaluasiPenyediaResource($record))->response()->setStatusCode(201);
    }

    public function show(SmkiEvaluasiPenyedia $evaluasiPenyedia)
    {
        Gate::authorize('view', $evaluasiPenyedia);

        return new SmkiEvaluasiPenyediaResource($evaluasiPenyedia);
    }

    public function updateIdentifikasi(UpdateIdentifikasiEvaluasiPenyediaRequest $request, SmkiEvaluasiPenyedia $evaluasiPenyedia)
    {
        Gate::authorize('update', $evaluasiPenyedia);
        $record = $this->service->updateIdentifikasi(
            $evaluasiPenyedia,
            $request->validated(),
            $request->validated('version'),
            $request->user()
        );

        return new SmkiEvaluasiPenyediaResource($record);
    }

    public function updatePenilaian(UpdatePenilaianEvaluasiPenyediaRequest $request, SmkiEvaluasiPenyedia $evaluasiPenyedia)
    {
        Gate::authorize('update', $evaluasiPenyedia);
        $record = $this->service->savePenilaian(
            $evaluasiPenyedia,
            $request->validated('penilaian'),
            $request->validated('version'),
            $request->user()
        );

        return new SmkiEvaluasiPenyediaResource($record);
    }

    public function hitung(HitungEvaluasiPenyediaRequest $request, SmkiEvaluasiPenyedia $evaluasiPenyedia)
    {
        Gate::authorize('update', $evaluasiPenyedia);
        $record = $this->service->hitung(
            $evaluasiPenyedia,
            $request->validated('version'),
            $request->user()
        );

        return new SmkiEvaluasiPenyediaResource($record);
    }

    public function ajukan(AjukanEvaluasiPenyediaRequest $request, SmkiEvaluasiPenyedia $evaluasiPenyedia)
    {
        Gate::authorize('ajukan', $evaluasiPenyedia);
        $record = $this->service->ajukan(
            $evaluasiPenyedia,
            (int) $request->validated('admin_id'),
            $request->validated('version'),
            $request->user()
        );

        return new SmkiEvaluasiPenyediaResource($record);
    }

    public function setujui(SetujuiEvaluasiPenyediaRequest $request, SmkiEvaluasiPenyedia $evaluasiPenyedia)
    {
        Gate::authorize('approve', $evaluasiPenyedia);
        $record = $this->service->setujui(
            $evaluasiPenyedia,
            $request->validated('version'),
            $request->user()
        );

        return new SmkiEvaluasiPenyediaResource($record);
    }

    public function kembalikan(KembalikanEvaluasiPenyediaRequest $request, SmkiEvaluasiPenyedia $evaluasiPenyedia)
    {
        Gate::authorize('approve', $evaluasiPenyedia);
        $record = $this->service->kembalikan(
            $evaluasiPenyedia,
            (string) $request->validated('catatan'),
            $request->validated('version'),
            $request->user()
        );

        return new SmkiEvaluasiPenyediaResource($record);
    }

    public function riwayat(SmkiEvaluasiPenyedia $evaluasiPenyedia)
    {
        Gate::authorize('view', $evaluasiPenyedia);
        $logs = SmkiEvaluasiPenyediaLog::where('evaluasi_id', $evaluasiPenyedia->id)
            ->with(['user', 'rekomendasi'])
            ->orderBy('created_at')->orderBy('id')
            ->get();

        return response()->json(['data' => $logs->map(fn ($log) => $this->mapLog($log))->values()]);
    }

    public function snapshot(SmkiEvaluasiPenyedia $evaluasiPenyedia, SmkiEvaluasiPenyediaLog $log)
    {
        Gate::authorize('view', $evaluasiPenyedia);
        if ((int) $log->evaluasi_id !== (int) $evaluasiPenyedia->id) {
            abort(404, 'Snapshot penilaian tidak ditemukan.');
        }
        $log->loadMissing(['user', 'rekomendasi']);

        return response()->json(['data' => $this->mapLog($log)]);
    }

    public function destroy(DeleteEvaluasiPenyediaRequest $request, SmkiEvaluasiPenyedia $evaluasiPenyedia)
    {
        Gate::authorize('delete', $evaluasiPenyedia);
        $this->service->delete($evaluasiPenyedia, (int) $request->validated('version'), $request->user());

        return response()->noContent();
    }

    private function mapLog(SmkiEvaluasiPenyediaLog $log): array
    {
        $penilaian = null;
        if (is_array($log->penilaian_snapshot)) {
            $penilaian = array_map(function ($row) {
                $row = is_array($row) ? $row : [];
                return [
                    'kriteria_id' => isset($row['kriteria_id']) ? (int) $row['kriteria_id'] : null,
                    'skala_id' => isset($row['skala_id']) ? (int) $row['skala_id'] : null,
                    'skor' => isset($row['skor']) ? (int) $row['skor'] : 0,
                    'kriteria' => [
                        'id' => isset($row['kriteria_id']) ? (int) $row['kriteria_id'] : null,
                        'urutan' => isset($row['urutan']) ? (int) $row['urutan'] : null,
                        'nama_kriteria' => $row['kriteria_nama'] ?? null,
                    ],
                    'skala' => [
                        'id' => isset($row['skala_id']) ? (int) $row['skala_id'] : null,
                        'label' => $row['skala_label'] ?? null,
                        'skor' => isset($row['skor']) ? (int) $row['skor'] : 0,
                    ],
                ];
            }, $log->penilaian_snapshot);
        }

        return [
            'id' => (int) $log->id,
            'aksi' => $log->aksi,
            'siklus' => (int) $log->siklus,
            'catatan' => $log->catatan,
            'total_nilai' => $log->total_nilai === null ? null : (int) $log->total_nilai,
            'rekomendasi_id' => $log->rekomendasi_id === null ? null : (int) $log->rekomendasi_id,
            'rekomendasi' => $log->rekomendasi ? [
                'id' => (int) $log->rekomendasi->id,
                'kategori' => $log->rekomendasi->kategori,
                'nilai_min' => (int) $log->rekomendasi->nilai_min,
                'nilai_max' => (int) $log->rekomendasi->nilai_max,
                'keterangan' => $log->rekomendasi->keterangan,
            ] : null,
            'user' => $log->user ? [
                'id' => (int) $log->user->id,
                'name' => $log->user->name,
                'position' => $log->user->position,
            ] : null,
            'created_at' => $log->created_at?->toISOString(),
            'penilaian' => $penilaian,
        ];
    }
}
