<?php

namespace App\Http\Controllers\Smki;

use App\Http\Controllers\Controller;
use App\Http\Requests\Smki\TindakanPerbaikan\StoreTindakanPerbaikanRequest;
use App\Http\Requests\Smki\TindakanPerbaikan\SubmitTindakanPerbaikanRequest;
use App\Http\Requests\Smki\TindakanPerbaikan\UpdateTindakanPerbaikanRequest;
use App\Http\Requests\Smki\TindakanPerbaikan\VerifyTindakanPerbaikanRequest;
use App\Http\Resources\Smki\SmkiTindakanPerbaikanResource;
use App\Models\Auditor;
use App\Models\SmkiTindakanPerbaikan;
use App\Models\SmkiUnitKerja;
use App\Models\User;
use App\Services\Documents\DocxToPdfService;
use App\Services\Smki\TindakanPerbaikanService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

class SmkiTindakanPerbaikanController extends Controller
{
    public function __construct(private TindakanPerbaikanService $service, private DocxToPdfService $pdfService) {}

    public function index(Request $request)
    {
        Gate::authorize('viewAny', SmkiTindakanPerbaikan::class);
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in(['draft', 'submitted', 'Open', 'Close'])],
            'verifikasi' => ['nullable', Rule::in(['Open', 'Close'])],
            'source' => ['nullable', Rule::in(array_keys(SmkiTindakanPerbaikan::SOURCES))],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $query = SmkiTindakanPerbaikan::visibleTo($request->user());
        if (filled($filters['search'] ?? null)) {
            $query->where(function ($query) use ($filters) {
                foreach (['nomor_laporan', 'area', 'deskripsi', 'dilaporkan_oleh_nama'] as $field) {
                    $query->orWhere($field, 'like', '%'.$filters['search'].'%');
                }
            });
        }
        if (filled($filters['status'] ?? null)) {
            $query->where(in_array($filters['status'], ['Open', 'Close'], true) ? 'verifikasi' : 'status', $filters['status']);
        }
        if (filled($filters['source'] ?? null)) {
            $query->whereJsonContains('sumber_codes', $filters['source']);
        }
        if (filled($filters['verifikasi'] ?? null)) {
            $query->where('verifikasi', $filters['verifikasi']);
        }
        $summary = [
            'total' => (clone $query)->count(),
            'needs_follow_up' => (clone $query)->where('status', 'submitted')->where(fn ($query) => $query->whereNull('verifikasi')->orWhere('verifikasi', 'Open'))->count(),
            'closed' => (clone $query)->where('verifikasi', 'Close')->count(),
        ];
        $records = $query->with(['items', 'verifications'])->latest('id')->paginate($filters['per_page'] ?? 10);

        return response()->json([
            'data' => SmkiTindakanPerbaikanResource::collection($records->items())->resolve($request),
            'meta' => ['current_page' => $records->currentPage(), 'last_page' => $records->lastPage(),
                'total' => $records->total(), 'per_page' => $records->perPage()],
            'summary' => $summary,
        ]);
    }

    public function lookup(Request $request)
    {
        Gate::authorize('create', SmkiTindakanPerbaikan::class);
        $pdfAvailable = false;
        try {
            $this->pdfService->assertAvailable();
            $pdfAvailable = true;
        } catch (ServiceUnavailableHttpException) {
        }

        return response()->json(['data' => [
            'units' => SmkiUnitKerja::orderBy('nama_unit_kerja')->get(['id_unit_kerja', 'nama_unit_kerja']),
            'users' => User::where('bidang_id', $request->user()->bidang_id)->where('is_active', 1)
                ->orderBy('name')->get(['id', 'name', 'position']),
            'auditors' => Auditor::orderBy('nama_auditor')->get(['id_auditor', 'nama_auditor', 'nip_auditor']),
            'sources' => collect(SmkiTindakanPerbaikan::SOURCES)->map(fn ($label, $code) => compact('code', 'label'))->values(),
            'template' => ['code' => 'FR-009/ KOM.03.05/SANDIKAMI', 'revision' => '3.0',
                'effective_date' => '2023-09-11', 'classification' => 'INTERNAL'],
            'export_pdf_available' => $pdfAvailable,
        ]]);
    }

    public function store(StoreTindakanPerbaikanRequest $request)
    {
        return (new SmkiTindakanPerbaikanResource($this->service->create($request->validated(), $request->user())))
            ->response()->setStatusCode(201);
    }

    public function show(SmkiTindakanPerbaikan $tindakanPerbaikan)
    {
        Gate::authorize('view', $tindakanPerbaikan);

        return new SmkiTindakanPerbaikanResource($tindakanPerbaikan->load(['items', 'verifications']));
    }

    public function update(UpdateTindakanPerbaikanRequest $request, SmkiTindakanPerbaikan $tindakanPerbaikan)
    {
        return new SmkiTindakanPerbaikanResource($this->service->update($tindakanPerbaikan, $request->validated(), $request->user()));
    }

    public function destroy(SubmitTindakanPerbaikanRequest $request, SmkiTindakanPerbaikan $tindakanPerbaikan)
    {
        $this->service->delete($tindakanPerbaikan, $request->validated('version'), $request->user());

        return response()->noContent();
    }

    public function submit(SubmitTindakanPerbaikanRequest $request, SmkiTindakanPerbaikan $tindakanPerbaikan)
    {
        return new SmkiTindakanPerbaikanResource($this->service->submit($tindakanPerbaikan, $request->validated('version'), $request->user()));
    }

    public function verify(VerifyTindakanPerbaikanRequest $request, SmkiTindakanPerbaikan $tindakanPerbaikan)
    {
        return new SmkiTindakanPerbaikanResource($this->service->verify($tindakanPerbaikan, $request->validated(), $request->user()));
    }
}
