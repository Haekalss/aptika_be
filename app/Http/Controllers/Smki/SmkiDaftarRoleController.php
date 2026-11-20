<?php

namespace App\Http\Controllers\Smki;

use App\Http\Controllers\Controller;
use App\Http\Requests\Smki\SaveDaftarRoleRequest;
use App\Http\Resources\Smki\DaftarRoleResource;
use App\Models\Bidang;
use App\Models\Pegawai;
use App\Models\SmkiDaftarRole;
use App\Services\Smki\DaftarRoleService;
use App\Services\SmkiDaftarRoleDocxService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class SmkiDaftarRoleController extends Controller
{
    public function __construct(
        private readonly DaftarRoleService $service,
        private readonly SmkiDaftarRoleDocxService $docxService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('create', SmkiDaftarRole::class);
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['Draf', 'Tersimpan'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $base = SmkiDaftarRole::query();
        $all = (clone $base)->get();
        $stats = [
            'total' => $all->count(),
            'draf' => $all->where('status', 'Draf')->count(),
            'tersimpan' => $all->where('status', 'Tersimpan')->count(),
            'total_roles' => $all->sum(fn (SmkiDaftarRole $record) => count($record->payload['roles'] ?? [])),
        ];

        $query = $base;
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['search'])) {
            $term = '%'.$filters['search'].'%';
            $query->where(function ($forms) use ($term) {
                $forms->where('nomor', 'like', $term)
                    ->orWhere('unit_name', 'like', $term)
                    ->orWhere('recorder_name', 'like', $term)
                    ->orWhere('payload', 'like', $term);
            });
        }

        $records = $query->latest('id')->paginate($filters['per_page'] ?? 15);

        return response()->json([
            'success' => true,
            'data' => DaftarRoleResource::collection($records)->resolve($request),
            'stats' => $stats,
            'meta' => [
                'total' => $records->total(),
                'per_page' => $records->perPage(),
                'current_page' => $records->currentPage(),
                'last_page' => $records->lastPage(),
            ],
        ]);
    }

    public function lookup(Request $request): JsonResponse
    {
        return response()->json(['success' => true, 'data' => [
            'bidangs' => Bidang::orderBy('name')->get(['id', 'name', 'code']),
            'employees' => Pegawai::orderBy('nama')->get(['id', 'nama', 'nip', 'jabatan'])->map(fn (Pegawai $item) => [
                'id' => $item->id,
                'name' => $item->nama,
                'nip' => $item->nip,
                'position' => $item->jabatan,
            ]),
        ]]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $record = SmkiDaftarRole::query()->findOrFail($id);
        Gate::authorize('view', $record);

        return response()->json([
            'success' => true,
            'data' => (new DaftarRoleResource($record))->toArray($request),
        ]);
    }

    public function store(SaveDaftarRoleRequest $request): JsonResponse
    {
        $existing = $this->service->findByClientId($request->input('clientId'), $request->user());
        if ($existing) {
            return response()->json([
                'success' => true,
                'data' => (new DaftarRoleResource($existing))->toArray($request),
            ]);
        }

        $record = $this->service->save(null, $request->validated(), $request->user());

        return response()->json([
            'success' => true,
            'data' => (new DaftarRoleResource($record))->toArray($request),
        ], 201);
    }

    public function update(SaveDaftarRoleRequest $request, string $id): JsonResponse
    {
        $form = SmkiDaftarRole::query()->findOrFail($id);
        Gate::authorize('update', $form);
        $record = $this->service->save($form, $request->validated(), $request->user());

        return response()->json([
            'success' => true,
            'data' => (new DaftarRoleResource($record))->toArray($request),
        ]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $form = SmkiDaftarRole::query()->findOrFail($id);
        Gate::authorize('delete', $form);
        $this->service->delete($form);

        return response()->json(['success' => true, 'message' => 'Formulir Daftar Role berhasil dihapus.']);
    }

    public function exportDocx(Request $request, string $id)
    {
        $form = SmkiDaftarRole::query()->findOrFail($id);
        Gate::authorize('view', $form);

        try {
            $data = (new DaftarRoleResource($form))->toArray($request);
            $path = $this->docxService->generateDocx([$data]);

            return response()->download($path, 'FR-033_Daftar-Role_'.$form->id.'.docx', [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ])->deleteFileAfterSend(true);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}
