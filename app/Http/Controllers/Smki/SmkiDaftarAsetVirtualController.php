<?php

namespace App\Http\Controllers\Smki;

use App\Http\Controllers\Controller;
use App\Http\Resources\Smki\DaftarAsetVirtualResource;
use App\Models\SmkiDaftarAsetVirtual;
use App\Services\Smki\DaftarAsetVirtualService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SmkiDaftarAsetVirtualController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('create', SmkiDaftarAsetVirtual::class);
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['Draf', 'Tersimpan'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $query = SmkiDaftarAsetVirtual::visibleTo($request->user());

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['search'])) {
            $term = '%'.$filters['search'].'%';
            $query->where(function ($forms) use ($term) {
                $forms->where('kode_aset', 'like', $term)
                    ->orWhere('nama_aset', 'like', $term)
                    ->orWhere('lokasi_aset', 'like', $term)
                    ->orWhere('no_dokumen', 'like', $term)
                    ->orWhereHas('instances', fn ($instances) => $instances
                        ->where('nama_instance', 'like', $term)
                        ->orWhere('kode_aset', 'like', $term)
                        ->orWhere('hostname', 'like', $term)
                        ->orWhere('alamat', 'like', $term));
            });
        }

        return DaftarAsetVirtualResource::collection(
            $query->withCount('instances')
                ->with('instances:id,daftar_aset_virtual_id,urutan,kode_aset,nama_instance,alamat,hostname,klasifikasi')
                ->latest('id')
                ->paginate($filters['per_page'] ?? 15),
        );
    }

    public function store(Request $request, DaftarAsetVirtualService $service)
    {
        Gate::authorize('create', SmkiDaftarAsetVirtual::class);
        $data = $this->validatedData($request);
        $form = $service->save(null, $data, $request->user());

        return (new DaftarAsetVirtualResource($form))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, string $daftar_aset_virtual)
    {
        return new DaftarAsetVirtualResource(
            $this->findVisible($request, $daftar_aset_virtual)->load('instances'),
        );
    }

    public function update(
        Request $request,
        string $daftar_aset_virtual,
        DaftarAsetVirtualService $service,
    ) {
        $form = $this->findVisible($request, $daftar_aset_virtual);
        Gate::authorize('update', $form);
        $data = $this->validatedData($request, $form->status, $form);
        $updated = $service->save($form, $data, $request->user());

        return new DaftarAsetVirtualResource($updated);
    }

    public function destroy(
        Request $request,
        string $daftar_aset_virtual,
        DaftarAsetVirtualService $service,
    ) {
        $form = $this->findVisible($request, $daftar_aset_virtual);
        Gate::authorize('delete', $form);
        $service->delete($form);

        return response()->json(['message' => 'Formulir FR-035 berhasil dihapus.']);
    }

    private function validatedData(
        Request $request,
        string $currentStatus = 'Draf',
        ?SmkiDaftarAsetVirtual $form = null,
    ): array {
        $status = $request->input('status', $currentStatus);
        $isSaved = $status === 'Tersimpan';
        $requiredWhenSaved = Rule::requiredIf($isSaved);
        $input = $request->all();

        if ($form) {
            foreach ([
                'no_dokumen',
                'no_revisi',
                'tanggal_berlaku',
                'kode_aset',
                'nama_aset',
                'lokasi_aset',
            ] as $field) {
                if (! array_key_exists($field, $input)) {
                    $input[$field] = $form->getAttribute($field);
                }
            }

            if (! array_key_exists('rows', $input)) {
                $input['rows'] = $form->instances()
                    ->get()
                    ->map(fn ($instance) => $instance->only([
                        'kode_aset',
                        'nama_instance',
                        'deskripsi',
                        'tipe_virtualisasi',
                        'alamat',
                        'hostname',
                        'spesifikasi',
                        'pemilik',
                        'klasifikasi',
                    ]))
                    ->all();
            }
        }

        $data = Validator::make($input, [
            'status' => ['sometimes', 'required', Rule::in(['Draf', 'Tersimpan'])],
            'no_dokumen' => [$requiredWhenSaved, 'nullable', 'string', 'max:255'],
            'no_revisi' => [$requiredWhenSaved, 'nullable', 'string', 'max:30'],
            'tanggal_berlaku' => [$requiredWhenSaved, 'nullable', 'string', 'max:30'],
            'kode_aset' => [$requiredWhenSaved, 'nullable', 'string', 'max:255'],
            'nama_aset' => [$requiredWhenSaved, 'nullable', 'string', 'max:255'],
            'lokasi_aset' => [$requiredWhenSaved, 'nullable', 'string', 'max:255'],
            'rows' => [$requiredWhenSaved, 'array', 'max:200'],
            'rows.*' => ['array'],
            'rows.*.kode_aset' => ['nullable', 'string', 'max:255'],
            'rows.*.nama_instance' => ['nullable', 'string', 'max:255'],
            'rows.*.deskripsi' => ['nullable', 'string'],
            'rows.*.tipe_virtualisasi' => ['nullable', 'string', 'max:255'],
            'rows.*.alamat' => ['nullable', 'string', 'max:255'],
            'rows.*.hostname' => ['nullable', 'string', 'max:255'],
            'rows.*.spesifikasi' => ['nullable', 'string'],
            'rows.*.pemilik' => ['nullable', 'string', 'max:255'],
            'rows.*.klasifikasi' => ['nullable', Rule::in(['Rahasia', 'Internal', 'Publik'])],
        ])->validate();

        $rows = collect($data['rows'] ?? []);
        $filledRows = $rows->filter(fn (array $row) => collect([
            'kode_aset',
            'nama_instance',
            'deskripsi',
            'tipe_virtualisasi',
            'alamat',
            'hostname',
            'spesifikasi',
            'pemilik',
            'klasifikasi',
        ])->contains(fn ($field) => trim((string) ($row[$field] ?? '')) !== ''))->values();

        if ($isSaved) {
            if ($filledRows->isEmpty()) {
                throw ValidationException::withMessages([
                    'rows' => ['Isi setidaknya satu baris instance virtualisasi.'],
                ]);
            }

            foreach ($filledRows as $index => $row) {
                $hasGeneratedCode = preg_match('/^(\d+)(\/.*)$/', trim((string) ($data['kode_aset'] ?? ''))) === 1;
                if (
                    (trim((string) ($row['kode_aset'] ?? '')) === '' && ! $hasGeneratedCode)
                    || trim((string) ($row['nama_instance'] ?? '')) === ''
                    || empty($row['klasifikasi'])
                ) {
                    throw ValidationException::withMessages([
                        "rows.{$index}" => ['Setiap instance yang diisi wajib memiliki kode aset, nama instance, dan klasifikasi.'],
                    ]);
                }
            }
        } elseif ($filledRows->isEmpty() && collect([
            'no_dokumen',
            'no_revisi',
            'tanggal_berlaku',
            'kode_aset',
            'nama_aset',
            'lokasi_aset',
        ])->every(fn ($field) => trim((string) ($data[$field] ?? '')) === '')) {
            throw ValidationException::withMessages([
                'form' => ['Isi minimal satu data sebelum menyimpan draf.'],
            ]);
        }

        $data['status'] = $status;
        if ($request->exists('rows')) {
            $data['rows'] = $filledRows->all();
        } else {
            unset($data['rows']);
        }

        return $data;
    }

    private function findVisible(Request $request, string $id): SmkiDaftarAsetVirtual
    {
        $form = SmkiDaftarAsetVirtual::visibleTo($request->user())->findOrFail($id);
        Gate::authorize('view', $form);

        return $form;
    }
}
