<?php

namespace App\Http\Controllers\Smki;

use App\Http\Controllers\Controller;
use App\Models\Bidang;
use App\Models\DaftarAsetTi;
use App\Models\MasterSistemAplikasi;
use App\Models\Pegawai;
use App\Models\SmkiLogPerubahan;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SmkiLogPerubahanController extends Controller
{
    private const OUTCOMES = ['Berhasil', 'Berhasil Sebagian', 'Gagal', 'Dikembalikan ke Kondisi Semula'];

    private const FIELDS = [
        'title', 'startedAt', 'endedAt', 'before', 'action', 'after', 'testing',
        'outcome', 'obstacles', 'followUp', 'recovery', 'needsFollowUp', 'attachments',
        'documentNumber', 'documentRevision', 'documentEffectiveDate',
    ];

    // FR-032 is shared across bidang; other modules retain their existing scope.
    private function scoped(Request $request): Builder
    {
        return SmkiLogPerubahan::withoutGlobalScope('bidang_scope');
    }

    private function findScoped(Request $request, string $id): SmkiLogPerubahan
    {
        return $this->scoped($request)->findOrFail($id);
    }

    public function index(Request $request): JsonResponse
    {
        $logs = $this->scoped($request)->orderByDesc('created_at')->orderByDesc('id')->get();
        return response()->json(['success' => true, 'data' => $logs->map(fn ($log) => $this->present($log))]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->present($this->findScoped($request, $id))]);
    }

    public function lookup(Request $request): JsonResponse
    {
        $request->validate(['bidang_id' => ['nullable', 'integer', 'min:1']]);
        $bidang = $this->resolveBidang($request, (int) ($request->input('bidang_id') ?? $request->user()->bidang_id));
        $objects = MasterSistemAplikasi::orderBy('nama_sistem')->get()->map(fn ($item) => [
            'id' => $item->getKey(), 'kind' => 'sistem', 'name' => $item->nama_sistem, 'label' => $item->nama_sistem,
        ])->concat(DaftarAsetTi::withoutGlobalScope('bidang_scope')->where('bidang_id', $bidang->id)->orderBy('nama_aset')->get()->map(fn ($item) => [
            'id' => $item->id, 'kind' => 'aset', 'name' => $item->nama_aset,
            'label' => implode(' · ', array_filter([$item->kode, $item->nama_aset])),
        ]));
        return response()->json(['success' => true, 'data' => [
            'bidang' => ['id' => $bidang->id, 'name' => $bidang->name],
            'employees' => Pegawai::orderBy('nama')->get(['id', 'nama', 'jabatan'])->map(fn ($item) => [
                'id' => $item->id, 'name' => $item->nama, 'position' => $item->jabatan,
            ]),
            'objects' => $objects->values(),
            'canAccessAllBidang' => true,
            'bidangs' => Bidang::orderBy('name')->get(['id', 'name']),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validateInput($request->all());
        $bidang = $this->resolveBidang($request, (int) ($validated['references']['bidangId'] ?? $request->user()->bidang_id));
        $clientLogId = $request->input('clientLogId');
        if ($clientLogId !== null) {
            Validator::make(['clientLogId' => $clientLogId], ['clientLogId' => ['required', 'uuid']])->validate();
            $existing = $this->scoped($request)->where('bidang_id', $bidang->id)->where('client_log_id', $clientLogId)->first();
            if ($existing) return response()->json(['success' => true, 'data' => $this->present($existing)]);
        }
        $object = $this->resolveObject($bidang->id, $validated['references']);
        $employee = $this->resolveEmployee($validated['references']['executorId'] ?? null);
        $payload = $this->payload($validated);

        $log = DB::transaction(function () use ($request, $payload, $validated, $object, $employee, $clientLogId, $bidang) {
            $log = SmkiLogPerubahan::create([
                'client_log_id' => $clientLogId,
                'bidang_id' => $bidang->id,
                'recorder_id' => $request->user()->id,
                'recorder_name' => $request->user()->name,
                'unit_name' => $bidang->name,
                'object_kind' => $validated['references']['objectKind'],
                'object_id' => $validated['references']['objectId'],
                'object_label' => $object,
                'executor_id' => $employee?->id,
                'executor_name' => $employee?->nama,
                'status' => 'Draf',
                'payload' => $payload,
            ]);
            $log->nomor_log = sprintf('LOG-%s-%06d', now()->year, $log->id);
            $log->save();
            return $log;
        });

        return response()->json(['success' => true, 'data' => $this->present($log)], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $log = DB::transaction(function () use ($request, $id) {
            $log = $this->scoped($request)->lockForUpdate()->findOrFail($id);
            if ($log->status !== 'Draf') abort(409, 'Log Final tidak dapat diubah.');
            $validated = $this->validateInput($request->all());
            if (isset($validated['references']['bidangId']) && (int) $validated['references']['bidangId'] !== $log->bidang_id) {
                throw ValidationException::withMessages(['references.bidangId' => 'Bidang pemilik log tidak dapat diubah.']);
            }
            $object = $this->resolveObject($log->bidang_id, $validated['references']);
            $employee = $this->resolveEmployee($validated['references']['executorId'] ?? null);
            $log->update([
                'object_kind' => $validated['references']['objectKind'],
                'object_id' => $validated['references']['objectId'],
                'object_label' => $object,
                'executor_id' => $employee?->id,
                'executor_name' => $employee?->nama,
                'payload' => $this->payload($validated),
            ]);
            return $log;
        });
        return response()->json(['success' => true, 'data' => $this->present($log)]);
    }

    public function finalize(Request $request, string $id): JsonResponse
    {
        $log = DB::transaction(function () use ($request, $id) {
            $log = $this->scoped($request)->lockForUpdate()->findOrFail($id);
            if ($log->status !== 'Draf') abort(409, 'Log sudah Final.');
            $this->resolveObject($log->bidang_id, ['objectKind' => $log->object_kind, 'objectId' => $log->object_id]);
            $employee = $this->resolveEmployee($log->executor_id);
            $this->validateFinal($log->payload, $employee);
            $log->update(['status' => 'Final', 'finalized_at' => now()]);
            return $log;
        });
        return response()->json(['success' => true, 'data' => $this->present($log)]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        DB::transaction(function () use ($request, $id) {
            $log = $this->scoped($request)->lockForUpdate()->findOrFail($id);
            if ($log->status !== 'Draf') abort(409, 'Log Final tidak dapat dihapus.');
            $log->delete();
        });
        return response()->json(['success' => true, 'message' => 'Draf log berhasil dihapus.']);
    }

    private function validateInput(array $input): array
    {
        $rules = [
            'documentNumber' => ['nullable', 'string', 'max:255'],
            'documentRevision' => ['nullable', 'string', 'max:255'],
            'documentEffectiveDate' => ['nullable', 'date_format:Y-m-d'],
            'title' => ['required', 'string', 'max:255'],
            'startedAt' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'endedAt' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'before' => ['nullable', 'string', 'max:5000'],
            'action' => ['nullable', 'string', 'max:5000'],
            'after' => ['nullable', 'string', 'max:5000'],
            'testing' => ['nullable', 'string', 'max:5000'],
            'outcome' => ['nullable', Rule::in(self::OUTCOMES)],
            'obstacles' => ['nullable', 'string', 'max:5000'],
            'followUp' => ['nullable', 'string', 'max:5000'],
            'recovery' => ['nullable', 'string', 'max:5000'],
            'needsFollowUp' => ['nullable', 'boolean'],
            'attachments' => ['nullable', 'array', 'max:5'],
            'attachments.*' => ['array:id,name,size,dataUrl'],
            'attachments.*.id' => ['required', 'uuid'],
            'attachments.*.name' => ['required', 'string', 'max:255'],
            'attachments.*.size' => ['required', 'integer', 'min:1', 'max:3145728'],
            'attachments.*.dataUrl' => ['required', 'string', 'max:4194500'],
            'references' => ['required', 'array'],
            'references.bidangId' => ['nullable', 'integer', 'min:1'],
            'references.objectKind' => ['required', Rule::in(['sistem', 'aset'])],
            'references.objectId' => ['required', 'integer', 'min:1'],
            'references.executorId' => ['nullable', 'integer', 'min:1'],
        ];
        if (!empty($input['startedAt'])) $rules['endedAt'][] = 'after_or_equal:startedAt';
        $validated = Validator::make($input, $rules)->validate();
        $bytes = 0;
        foreach ($validated['attachments'] ?? [] as $index => $attachment) {
            $extension = strtolower(pathinfo($attachment['name'], PATHINFO_EXTENSION));
            $mime = match ($extension) {
                'pdf' => 'application/pdf', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'png' => 'image/png', 'jpg', 'jpeg' => 'image/jpeg', default => null,
            };
            if (!$mime || !preg_match('/^data:([^;,]*);base64,([A-Za-z0-9+\/=]+)$/D', $attachment['dataUrl'], $matches)) {
                throw ValidationException::withMessages(["attachments.$index" => 'Jenis lampiran tidak valid.']);
            }
            $binary = base64_decode($matches[2], true);
            $signature = $binary === false ? '' : substr($binary, 0, 4);
            $validSignature = match ($extension) {
                'pdf' => $signature === '%PDF', 'docx', 'xlsx' => $signature === "PK\x03\x04",
                'png' => $signature === "\x89PNG", 'jpg', 'jpeg' => substr((string) $binary, 0, 3) === "\xFF\xD8\xFF",
            };
            if (!$validSignature || strlen($binary) !== $attachment['size'] || ($matches[1] !== $mime && $matches[1] !== 'application/octet-stream')) {
                throw ValidationException::withMessages(["attachments.$index" => 'Isi lampiran tidak sesuai nama atau ukuran berkas.']);
            }
            $bytes += strlen($binary);
        }
        if ($bytes > 3 * 1024 * 1024) throw ValidationException::withMessages(['attachments' => 'Total lampiran maksimal 3 MB.']);
        return $validated;
    }

    private function payload(array $validated): array
    {
        $payload = [];
        foreach (self::FIELDS as $field) $payload[$field] = $validated[$field] ?? match ($field) {
            'needsFollowUp' => false, 'attachments' => [], default => '',
        };
        return $payload;
    }

    private function validateFinal(array $payload, ?Pegawai $employee): void
    {
        $required = ['title', 'startedAt', 'endedAt', 'before', 'action', 'after', 'testing', 'outcome'];
        $errors = [];
        foreach ($required as $field) if (!trim((string) ($payload[$field] ?? ''))) $errors[$field] = 'Bagian ini wajib diisi sebelum finalisasi.';
        if (!$employee) $errors['references.executorId'] = 'Pilih pelaksana dari master pegawai.';
        if (($payload['needsFollowUp'] ?? false) && !trim((string) ($payload['followUp'] ?? ''))) {
            $errors['followUp'] = 'Tindak lanjut wajib diisi.';
        }
        if ($errors) throw ValidationException::withMessages($errors);
    }

    private function resolveBidang(Request $request, int $id): Bidang
    {
        return Bidang::findOrFail($id);
    }

    private function resolveObject(int $bidangId, array $references): string
    {
        if ($references['objectKind'] === 'sistem') {
            $name = MasterSistemAplikasi::whereKey($references['objectId'])->value('nama_sistem');
        } else {
            $asset = DaftarAsetTi::withoutGlobalScope('bidang_scope')->where('bidang_id', $bidangId)
                ->whereKey($references['objectId'])->first();
            $name = $asset ? implode(' · ', array_filter([$asset->kode, $asset->nama_aset])) : null;
        }
        if (!$name) throw ValidationException::withMessages(['references.objectId' => 'Sistem atau aset tidak tersedia untuk bidang ini.']);
        return $name;
    }

    private function resolveEmployee(?int $id): ?Pegawai
    {
        if ($id === null) return null;
        $employee = Pegawai::find($id);
        if (!$employee) throw ValidationException::withMessages(['references.executorId' => 'Pelaksana tidak tersedia di master pegawai.']);
        return $employee;
    }

    private function present(SmkiLogPerubahan $log): array
    {
        return array_merge($log->payload, [
            'id' => (string) $log->id,
            'clientLogId' => $log->client_log_id,
            'number' => $log->nomor_log,
            'system' => $log->object_label,
            'unit' => $log->unit_name,
            'executor' => $log->executor_name ?? '',
            'status' => $log->status,
            'createdAt' => $log->created_at->toIso8601String(),
            'updatedAt' => $log->updated_at->toIso8601String(),
            'finalizedAt' => $log->finalized_at?->toIso8601String() ?? '',
            'references' => [
                'bidangId' => $log->bidang_id,
                'recorderId' => $log->recorder_id,
                'recorderName' => $log->recorder_name,
                'executorId' => $log->executor_id,
                'objectKind' => $log->object_kind,
                'objectId' => $log->object_id,
            ],
        ]);
    }
}
