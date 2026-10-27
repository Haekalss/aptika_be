<?php

namespace App\Http\Controllers\Smki;

use App\Http\Controllers\Controller;
use App\Http\Requests\Smki\SaveRemovableMediaRequest;
use App\Http\Resources\Smki\RemovableMediaResource;
use App\Models\SmkiRemovableMediaReport;
use App\Services\Smki\RemovableMediaLookup;
use App\Services\Smki\RemovableMediaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class SmkiRemovableMediaController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('create', SmkiRemovableMediaReport::class);
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', Rule::in(['draft', 'final'])],
            'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'between:1,50'],
        ]);
        $query = SmkiRemovableMediaReport::visibleTo($request->user());
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['search'])) {
            $term = '%'.$filters['search'].'%';
            $query->where(fn ($q) => $q->where('unit_kerja_nama', 'like', $term)->orWhere('pengurus_barang_nama', 'like', $term)
                ->orWhere('id', $filters['search'])->orWhereHas('items', fn ($items) => $items->where('nama_barang', 'like', $term)->orWhere('pic_nama', 'like', $term)));
        }

        return RemovableMediaResource::collection($query->withCount('items')->withSum('items', 'jumlah')->latest('id')->paginate($filters['per_page'] ?? 15));
    }

    public function lookup(Request $request, RemovableMediaLookup $lookup)
    {
        Gate::authorize('create', SmkiRemovableMediaReport::class);
        $data = $request->validate(['report_id' => ['nullable', 'integer', 'min:1']]);
        $report = isset($data['report_id']) ? $this->find($request, $data['report_id']) : null;

        return response()->json(['data' => $lookup->forUser($request->user(), $report)]);
    }

    public function store(SaveRemovableMediaRequest $request, RemovableMediaService $service)
    {
        return (new RemovableMediaResource($service->save(null, $request->validated(), $request->user())))->response()->setStatusCode(201);
    }

    public function show(Request $request, string $removable_media)
    {
        return new RemovableMediaResource($this->find($request, $removable_media)->load('items'));
    }

    public function update(SaveRemovableMediaRequest $request, SmkiRemovableMediaReport $removable_media, RemovableMediaService $service)
    {
        return new RemovableMediaResource($service->save($removable_media, $request->validated(), $request->user()));
    }

    public function destroy(Request $request, SmkiRemovableMediaReport $removable_media, RemovableMediaService $service)
    {
        Gate::authorize('delete', $removable_media);
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $service->delete($removable_media, $data['version']);

        return response()->json(['message' => 'Dokumen FR-025 berhasil dihapus.']);
    }

    public function exportData(Request $request, string $removable_media)
    {
        $report = $this->find($request, $removable_media);
        Gate::authorize('export', $report);

        return (new RemovableMediaResource($report->load('items')))->response()->header('Cache-Control', 'private, no-store');
    }

    private function find(Request $request, int|string $id): SmkiRemovableMediaReport
    {
        $report = SmkiRemovableMediaReport::visibleTo($request->user())->findOrFail($id);
        Gate::authorize('view', $report);

        return $report;
    }
}
