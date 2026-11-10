<?php

namespace App\Http\Controllers\Smki;

use App\Http\Controllers\Controller;
use App\Http\Requests\Smki\SaveBackupRestoreReportRequest;
use App\Http\Resources\Smki\BackupRestoreReportResource;
use App\Models\DaftarAsetTi;
use App\Models\SmkiBackupRestoreReport;
use App\Models\SmkiDaftarAsetVirtual;
use App\Models\SmkiDaftarAsetVirtualInstance;
use App\Services\Smki\BackupRestoreService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class SmkiBackupRestoreController extends Controller
{
    public function lookup(Request $request)
    {
        Gate::authorize('create', SmkiBackupRestoreReport::class);
        $filters = $request->validate(['asset_type' => ['required', Rule::in(['physical', 'virtual'])], 'search' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1']]);
        $user = $request->user();
        if ($filters['asset_type'] === 'physical') {
            $query = DaftarAsetTi::where('bidang_id', $user->bidang_id)
                ->select(['id', 'kode as kode_aset', 'nama_aset']);
            $name = 'nama_aset';
            $code = 'kode';
        } else {
            $parents = SmkiDaftarAsetVirtual::visibleTo($user)->where('bidang_id', $user->bidang_id)->select('id');
            $query = SmkiDaftarAsetVirtualInstance::whereIn('daftar_aset_virtual_id', $parents)
                ->select(['id', 'kode_aset', 'nama_instance as nama_aset']);
            $name = 'nama_instance';
            $code = 'kode_aset';
        }
        if (! empty($filters['search'])) {
            $term = '%'.$filters['search'].'%';
            $query->where(fn ($q) => $q->where($name, 'like', $term)->orWhere($code, 'like', $term));
        }

        return response()->json(['success' => true, 'data' => [
            'bidang' => ['id' => $user->bidang_id, 'nama' => $user->bidang->name],
            'assets' => $query->orderBy('id')->paginate(50),
        ]]);
    }

    public function index(Request $request)
    {
        Gate::authorize('create', SmkiBackupRestoreReport::class);
        $filters = $request->validate(['year' => ['nullable', 'integer', 'between:1900,2100'], 'status' => ['nullable', Rule::in(['draft', 'final'])], 'search' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'between:1,50']]);
        $query = SmkiBackupRestoreReport::visibleTo($request->user());
        if (isset($filters['year'])) {
            $query->where('tahun', $filters['year']);
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['search'])) {
            $term = '%'.$filters['search'].'%';
            $query->where(fn ($q) => $q->where('nomor_dokumen', 'like', $term)->orWhereHas('assets', fn ($a) => $a->where('nama_aset', 'like', $term)));
        }
        $page = $query->orderByDesc('updated_at')->orderByDesc('id')->paginate($filters['per_page'] ?? 10);
        $page->setCollection($page->getCollection()->map(fn ($item) => (new BackupRestoreReportResource($item))->resolve($request)));

        return response()->json(['success' => true, 'data' => $page]);
    }

    public function store(SaveBackupRestoreReportRequest $request, BackupRestoreService $service)
    {
        return (new BackupRestoreReportResource($service->save(null, $request->validated(), $request->user())))->response()->setStatusCode(201);
    }

    public function show(Request $request, string $report)
    {
        return new BackupRestoreReportResource($this->find($request, $report)->load('assets.entries.attachments'));
    }

    public function update(SaveBackupRestoreReportRequest $request, string $report, BackupRestoreService $service)
    {
        return new BackupRestoreReportResource($service->save($request->report(), $request->validated(), $request->user()));
    }

    public function destroy(Request $request, string $report, BackupRestoreService $service)
    {
        $record = $this->find($request, $report);
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $service->delete($record, $data['version'], $request->user());

        return response()->json(['success' => true, 'message' => 'Laporan FR-030 dihapus.']);
    }

    private function find(Request $request, string $id): SmkiBackupRestoreReport
    {
        Gate::authorize('create', SmkiBackupRestoreReport::class);
        $report = SmkiBackupRestoreReport::visibleTo($request->user())->findOrFail($id);
        Gate::authorize('view', $report);

        return $report;
    }
}
