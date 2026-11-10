<?php

namespace App\Http\Controllers\Smki;

use App\Http\Controllers\Controller;
use App\Http\Requests\Smki\SaveCapacityAssetRequest;
use App\Http\Requests\Smki\SaveCapacityLogRequest;
use App\Models\SmkiCapacityAsset;
use App\Services\Smki\CapacityPlanningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class SmkiCapacityPlanningController extends Controller
{
    public function index(Request $request, CapacityPlanningService $service): JsonResponse
    {
        Gate::authorize('create', SmkiCapacityAsset::class);
        $filters = $this->filters($request);

        return response()
            ->json(['data' => $service->dashboard($request->user(), $filters['year'], $filters['month'])])
            ->header('Cache-Control', 'private, no-store');
    }

    public function exportData(Request $request, CapacityPlanningService $service): JsonResponse
    {
        Gate::authorize('create', SmkiCapacityAsset::class);
        $filters = $this->filters($request);

        return response()
            ->json(['data' => $service->dashboard($request->user(), $filters['year'], $filters['month'])])
            ->header('Cache-Control', 'private, no-store');
    }

    public function store(SaveCapacityAssetRequest $request, CapacityPlanningService $service): JsonResponse
    {
        $asset = $service->createAsset($request->user(), $request->validated());

        return response()->json([
            'data' => $service->presentAsset($asset->load('logs')),
            'message' => 'Aset capacity planning berhasil ditambahkan.',
        ], 201);
    }

    public function update(SaveCapacityAssetRequest $request, string $asset, CapacityPlanningService $service): JsonResponse
    {
        $record = $this->find($request, $asset);
        $record = $service->updateAsset($record, $request->validated());

        return response()->json([
            'data' => $service->presentAsset($record->load('logs')),
            'message' => 'Aset capacity planning berhasil diperbarui.',
        ]);
    }

    public function destroy(Request $request, string $asset, CapacityPlanningService $service): JsonResponse
    {
        $record = $this->find($request, $asset);
        Gate::authorize('delete', $record);
        $service->deleteAsset($record);

        return response()->json(['message' => 'Aset dan seluruh log bulanannya berhasil dihapus.']);
    }

    public function saveLog(SaveCapacityLogRequest $request, string $asset, CapacityPlanningService $service): JsonResponse
    {
        $record = $this->find($request, $asset);
        Gate::authorize('update', $record);
        $log = $service->saveLog($record, $request->validated());

        return response()->json([
            'data' => $service->presentLog($log),
            'message' => 'Log bulanan berhasil disimpan.',
        ]);
    }

    private function find(Request $request, string $id): SmkiCapacityAsset
    {
        $asset = SmkiCapacityAsset::query()->visibleTo($request->user())->findOrFail($id);
        Gate::authorize('view', $asset);

        return $asset;
    }

    /** @return array{year:int, month:int} */
    private function filters(Request $request): array
    {
        $data = $request->validate([
            'year' => ['nullable', 'integer', 'between:2000,2100'],
            'month' => ['nullable', Rule::in(range(1, 12))],
        ]);
        $today = now('Asia/Jakarta');

        return [
            'year' => (int) ($data['year'] ?? $today->year),
            'month' => (int) ($data['month'] ?? $today->month),
        ];
    }
}
