<?php

namespace App\Http\Controllers\Smki;

use App\Http\Controllers\Controller;
use App\Models\MasterSistemAplikasi;
use App\Models\Pegawai;
use App\Models\SmkiReviewHakAkses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * FR-034: GET collection (page/per_page), GET lookup, GET item, POST, PUT, DELETE.
 * Writes accept the ReviewInput fields plus status Draf/Tersimpan; PUT replaces
 * the complete form. Output uses the frontend Review contract in {success,data}.
 * Creator/bidang and master display names are always determined by the server.
 * POST creates a new record each time; clients must not automatically retry it.
 */
class SmkiReviewHakAksesController extends Controller
{
    private const TEXT_FIELDS = ['documentNumber', 'documentRevision', 'location', 'preparedBy', 'acknowledgedBy'];
    private const DATE_FIELDS = ['documentEffectiveDate', 'periodStart', 'periodEnd', 'reviewDate', 'signedAt'];

    public function index(Request $request): JsonResponse
    {
        $params = $request->validate(['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'between:1,100']]);
        $page = SmkiReviewHakAkses::orderByDesc('created_at')->orderByDesc('id')->paginate($params['per_page'] ?? 50);

        return response()->json(['success' => true, 'data' => $page->getCollection()->map(fn ($review) => $this->present($review)),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]]);
    }

    public function lookup(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => [
            'systems' => MasterSistemAplikasi::orderBy('nama_sistem')->get()->map(fn ($item) => ['id' => (string) $item->getKey(), 'name' => $item->nama_sistem]),
            'employees' => Pegawai::orderBy('nama')->get()->map(fn ($item) => ['id' => (string) $item->id, 'name' => $item->nama]),
        ]]);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->present(SmkiReviewHakAkses::findOrFail($id))]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $bidang = $request->user()->bidang;
        abort_unless($bidang, 403, 'Pengguna belum terhubung ke bidang.');
        $review = DB::transaction(function () use ($request, $data, $bidang) {
            $review = SmkiReviewHakAkses::create($data + [
                'bidang_id' => $bidang->id, 'bidang_name' => $bidang->name,
                'recorder_id' => $request->user()->id, 'recorder_name' => $request->user()->name,
            ]);
            $review->update(['nomor_review' => sprintf('REV-%s-%06d', now()->year, $review->id)]);

            return $review;
        });

        return response()->json(['success' => true, 'data' => $this->present($review)], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $review = DB::transaction(function () use ($request, $id) {
            $review = SmkiReviewHakAkses::lockForUpdate()->findOrFail($id);
            $review->update($this->validated($request));

            return $review;
        });

        return response()->json(['success' => true, 'data' => $this->present($review)]);
    }

    public function destroy(string $id): JsonResponse
    {
        SmkiReviewHakAkses::findOrFail($id)->delete();

        return response()->json(['success' => true, 'message' => 'Review berhasil dihapus.']);
    }

    private function validated(Request $request): array
    {
        $rules = [
            'status' => ['required', Rule::in(['Draf', 'Tersimpan'])],
            'objectId' => ['nullable', 'integer', 'min:1', Rule::exists('master_sistem_aplikasi', 'id_sistem_aplikasi')],
            'reviewerId' => ['nullable', 'integer', 'min:1', Rule::exists('pegawai', 'id')],
            'followUp' => ['nullable', 'string', 'max:5000'],
            'accounts' => ['present', 'array', 'list', 'max:500'],
            'accounts.*' => ['array:id,username,currentAccess,userStatus'],
            'accounts.*.id' => ['required', 'uuid', 'distinct'],
            'accounts.*.username' => ['nullable', 'string', 'max:255'],
            'accounts.*.currentAccess' => ['nullable', 'string', 'max:255'],
            'accounts.*.userStatus' => ['nullable', Rule::in(['Aktif', 'Tidak Aktif'])],
        ];
        foreach (self::TEXT_FIELDS as $field) $rules[$field] = ['nullable', 'string', 'max:255'];
        foreach (self::DATE_FIELDS as $field) $rules[$field] = ['nullable', 'date_format:Y-m-d'];
        if ($request->filled('periodStart')) $rules['periodEnd'][] = 'after_or_equal:periodStart';
        if ($request->input('status') === 'Tersimpan') {
            foreach ([...self::TEXT_FIELDS, ...self::DATE_FIELDS, 'objectId', 'reviewerId', 'accounts.*.username', 'accounts.*.currentAccess', 'accounts.*.userStatus'] as $field) {
                $rules[$field] = array_values(array_filter($rules[$field], fn ($rule) => $rule !== 'nullable'));
                $rules[$field][] = 'required';
            }
            $rules['accounts'][] = 'min:1';
        }
        $validator = Validator::make($request->all(), $rules, [
            'required' => 'Bagian ini wajib diisi sebelum Simpan.',
            'exists' => 'Pilihan tidak tersedia pada master data.',
            'periodEnd.after_or_equal' => 'Akhir periode tidak boleh sebelum awal periode.',
        ]);
        $validator->after(function ($validator) use ($request) {
            $seen = [];
            foreach ((array) $request->input('accounts', []) as $index => $account) {
                $username = is_array($account) && is_string($account['username'] ?? null) ? mb_strtolower(trim($account['username'])) : '';
                if ($username !== '' && isset($seen[$username])) $validator->errors()->add("accounts.$index.username", 'User ID sudah dicatat pada baris lain.');
                $seen[$username] = true;
            }
        });
        $input = $validator->validate();
        $system = !empty($input['objectId']) ? MasterSistemAplikasi::findOrFail($input['objectId']) : null;
        $employee = !empty($input['reviewerId']) ? Pegawai::findOrFail($input['reviewerId']) : null;
        $payload = [];
        foreach ([...self::TEXT_FIELDS, ...self::DATE_FIELDS, 'followUp'] as $field) $payload[$field] = $input[$field] ?? '';
        $payload['accounts'] = array_map(fn ($account) => [
            'id' => $account['id'], 'username' => $account['username'] ?? '',
            'currentAccess' => $account['currentAccess'] ?? '', 'userStatus' => $account['userStatus'] ?? '',
        ], $input['accounts']);

        return ['status' => $input['status'], 'object_id' => $system?->getKey(), 'object_name' => $system?->nama_sistem ?? '',
            'reviewer_id' => $employee?->id, 'reviewer_name' => $employee?->nama ?? '', 'payload' => $payload];
    }

    private function present(SmkiReviewHakAkses $review): array
    {
        return array_merge($review->payload, [
            'id' => (string) $review->id, 'number' => $review->nomor_review,
            'title' => trim('Review Hak Akses '.$review->object_name), 'status' => $review->status,
            'bidangId' => $review->bidang_id, 'bidangName' => $review->bidang_name,
            'objectId' => (string) $review->object_id, 'objectName' => $review->object_name,
            'reviewerId' => (string) $review->reviewer_id, 'reviewer' => $review->reviewer_name,
            'recorderId' => $review->recorder_id ?? 0, 'recorderName' => $review->recorder_name,
            'createdAt' => $review->created_at->toIso8601String(), 'updatedAt' => $review->updated_at->toIso8601String(),
        ]);
    }
}
