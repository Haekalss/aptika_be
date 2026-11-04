<?php

namespace App\Http\Controllers\Smki;

use App\Http\Controllers\Controller;
use App\Models\MasterUnitKerja;
use App\Models\SmkiAsetSdm;
use App\Models\SmkiMatrixCompetency;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SmkiMatrixCompetencyController extends Controller
{
    public const PERAN = ['Kordinator', 'Team Teknis'];

    public const DEFAULT_COMPETENCIES = [
        'K-01', 'K-02', 'K-03', 'K-04', 'K-05', 'K-06', 'K-07', 'K-08',
        'K-09', 'K-10', 'K-11', 'K-12', 'K-13', 'K-14', 'K-15', 'K-16',
    ];

    public const LEVEL_LEGEND = [
        '0 - Tidak perlu memiliki pengetahuan',
        '1 - Harus memiliki pengetahuan dasar',
        '2 - Harus dilakukan dengan dukungan',
        '3 - Harus memiliki pengetahuan & melakukannya secara mandiri',
        '4 - Harus pandai teori dan praktis dan bisa melatih',
    ];

    public function index(Request $request)
    {
        $query = SmkiMatrixCompetency::withCount(['roles', 'employees', 'trainings'])
            ->orderByDesc('id');

        if ($request->filled('search')) {
            $search = trim($request->input('search'));
            if (strcasecmp($search, 'FR-031') !== 0) {
                $query->where(function ($q) use ($search) {
                    $q->where('nama_unit_kerja', 'like', "%{$search}%")
                        ->orWhere('nomor_dokumen', 'like', "%{$search}%");
                });
            }
        }

        $perPage = (int) $request->input('per_page', 10);
        if ($perPage === -1) {
            $items = $query->get();
            $meta = ['current_page' => 1, 'last_page' => 1, 'per_page' => $items->count(), 'total' => $items->count()];
        } else {
            $paginated = $query->paginate(max(1, min($perPage, 100)));
            $items = collect($paginated->items());
            $meta = [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ];
        }

        return response()->json([
            'success' => true,
            'data' => $items->map(fn ($d) => $this->summary($d))->values(),
            'meta' => $meta,
            'stats' => [
                'total_dokumen' => SmkiMatrixCompetency::count(),
                'total_roles' => \App\Models\SmkiMatrixRole::count(),
                'total_employees' => \App\Models\SmkiMatrixEmployee::count(),
            ],
        ]);
    }

    public function lookup(Request $request)
    {
        $nameCol = SmkiAsetSdm::UNIT_NAME_COLUMN;

        $pegawai = SmkiAsetSdm::orderBy('nama')
            ->get(['id', 'nama', 'nip', 'jabatan', 'unit_kerja_id'])
            ->map(fn ($p) => [
                'id' => $p->id,
                'nama' => $p->nama,
                'nip' => $p->nip,
                'jabatan' => $p->jabatan,
                'unit_kerja_id' => $p->unit_kerja_id,
            ])->values();

        $unit = MasterUnitKerja::orderBy($nameCol)
            ->get(["id_unit_kerja as id", "{$nameCol} as nama"]);

        return response()->json([
            'success' => true,
            'data' => [
                'peran' => self::PERAN,
                'legend' => self::LEVEL_LEGEND,
                'default_competencies' => self::DEFAULT_COMPETENCIES,
                'pegawai' => $pegawai,
                'unit_kerja' => $unit,
            ],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $this->validatePayload($request);

        $doc = DB::transaction(function () use ($validated, $request) {
            $doc = SmkiMatrixCompetency::create([
                'user_id' => $request->user()?->id,
                'bidang_id' => $request->user()?->bidang_id,
                'nomor_dokumen' => $validated['nomor_dokumen'] ?? null,
                'no_revisi' => $validated['no_revisi'] ?? null,
                'tanggal_berlaku' => $validated['tanggal_berlaku'] ?? null,
                'nama_unit_kerja' => $validated['nama_unit_kerja'] ?? null,
                'competencies' => $validated['competencies'],
            ]);
            $this->syncChildren($doc, $validated);
            return $doc;
        });

        return response()->json([
            'success' => true,
            'message' => 'Dokumen matriks kompetensi berhasil dibuat.',
            'data' => $this->transform($doc->load(['roles', 'employees', 'trainings'])),
        ], 201);
    }

    public function show($id)
    {
        $doc = SmkiMatrixCompetency::with(['roles', 'employees', 'trainings'])->findOrFail($id);

        return response()->json(['success' => true, 'data' => $this->transform($doc)]);
    }

    public function update(Request $request, $id)
    {
        $doc = SmkiMatrixCompetency::findOrFail($id);
        $validated = $this->validatePayload($request);

        DB::transaction(function () use ($doc, $validated) {
            $doc->update([
                'nomor_dokumen' => $validated['nomor_dokumen'] ?? null,
                'no_revisi' => $validated['no_revisi'] ?? null,
                'tanggal_berlaku' => $validated['tanggal_berlaku'] ?? null,
                'nama_unit_kerja' => $validated['nama_unit_kerja'] ?? null,
                'competencies' => $validated['competencies'],
            ]);
            $doc->roles()->delete();
            $doc->employees()->delete();
            $doc->trainings()->delete();
            $this->syncChildren($doc, $validated);
        });

        return response()->json([
            'success' => true,
            'message' => 'Dokumen matriks kompetensi berhasil diperbarui.',
            'data' => $this->transform($doc->fresh(['roles', 'employees', 'trainings'])),
        ]);
    }

    public function destroy($id)
    {
        SmkiMatrixCompetency::findOrFail($id)->delete();

        return response()->json(['success' => true, 'message' => 'Dokumen matriks kompetensi berhasil dihapus.']);
    }

    private function validatePayload(Request $request): array
    {
        $level = ['required', 'integer', 'min:0', 'max:4'];

        return $request->validate([
            'nomor_dokumen' => ['nullable', 'string', 'max:255'],
            'no_revisi' => ['nullable', 'string', 'max:50'],
            'tanggal_berlaku' => ['nullable', 'date'],
            'nama_unit_kerja' => ['nullable', 'string', 'max:255'],
            'competencies' => ['required', 'array', 'min:1', 'max:30'],
            'competencies.*' => ['required', 'string', 'max:120'],
            'roles' => ['nullable', 'array', 'max:200'],
            'roles.*.unit_label' => ['required_with:roles', 'string', 'max:255'],
            'roles.*.peran' => ['required_with:roles', Rule::in(self::PERAN)],
            'roles.*.levels' => ['required_with:roles', 'array', 'min:1', 'max:30'],
            'roles.*.levels.*' => $level,
            'employees' => ['nullable', 'array', 'max:500'],
            'employees.*.aset_sdm_id' => ['nullable', 'integer', 'exists:smki_aset_sdms,id'],
            'employees.*.nama' => ['nullable', 'string', 'max:255'],
            'employees.*.nip' => ['nullable', 'string', 'max:100'],
            'employees.*.jabatan' => ['nullable', 'string', 'max:255'],
            'employees.*.peran' => ['required_with:employees', Rule::in(self::PERAN)],
            'employees.*.levels' => ['required_with:employees', 'array', 'min:1', 'max:30'],
            'employees.*.levels.*' => $level,
            'employees.*.usulan_training' => ['nullable', 'string', 'max:2000'],
            'trainings' => ['nullable', 'array', 'max:500'],
            'trainings.*.nama' => ['required_with:trainings', 'string', 'max:255'],
            'trainings.*.jabatan' => ['required_with:trainings', 'string', 'max:255'],
            'trainings.*.usulan_training' => ['required_with:trainings', 'string', 'max:2000'],
        ], [
            'required' => ':attribute wajib diisi.',
            'competencies.required' => 'Daftar kompetensi wajib diisi.',
            'roles.*.levels.*.max' => 'Level kompetensi maksimal 4.',
            'employees.*.levels.*.max' => 'Level kompetensi maksimal 4.',
        ], [
            'nomor_dokumen' => 'Nomor dokumen',
            'no_revisi' => 'Nomor revisi',
            'tanggal_berlaku' => 'Tanggal berlaku',
            'nama_unit_kerja' => 'Nama unit kerja',
            'competencies' => 'Kompetensi',
        ]);
    }

    private function syncChildren(SmkiMatrixCompetency $doc, array $validated): void
    {
        foreach ($validated['roles'] ?? [] as $row) {
            $doc->roles()->create([
                'unit_label' => $row['unit_label'],
                'peran' => $row['peran'],
                'levels' => array_values($row['levels']),
            ]);
        }
        foreach ($validated['employees'] ?? [] as $row) {
            $doc->employees()->create([
                'aset_sdm_id' => $row['aset_sdm_id'] ?? null,
                'nama' => (string) ($row['nama'] ?? ''),
                'nip' => $row['nip'] ?? null,
                'jabatan' => (string) ($row['jabatan'] ?? ($row['peran'] ?? '')),
                'peran' => $row['peran'],
                'levels' => array_values($row['levels']),
                'usulan_training' => $row['usulan_training'] ?? null,
            ]);
        }
        foreach ($validated['trainings'] ?? [] as $row) {
            $doc->trainings()->create([
                'nama' => $row['nama'],
                'jabatan' => $row['jabatan'],
                'usulan_training' => $row['usulan_training'],
            ]);
        }
    }

    private function summary(SmkiMatrixCompetency $d): array
    {
        return [
            'id' => $d->id,
            'nomor_dokumen' => $d->nomor_dokumen,
            'no_revisi' => $d->no_revisi,
            'tanggal_berlaku' => $d->tanggal_berlaku?->format('Y-m-d'),
            'nama_unit_kerja' => $d->nama_unit_kerja,
            'jumlah_kompetensi' => is_array($d->competencies) ? count($d->competencies) : 0,
            'roles_count' => $d->roles_count ?? $d->roles()->count(),
            'employees_count' => $d->employees_count ?? $d->employees()->count(),
            'trainings_count' => $d->trainings_count ?? $d->trainings()->count(),
            'updated_at' => $d->updated_at?->toIso8601String(),
        ];
    }

    private function transform(SmkiMatrixCompetency $d): array
    {
        $competencies = array_values($d->competencies ?? self::DEFAULT_COMPETENCIES);
        $roles = $d->roles->map(fn ($r) => [
            'id' => $r->id,
            'unit_label' => $r->unit_label,
            'peran' => $r->peran,
            'levels' => array_values($r->levels ?? []),
        ])->values();

        $byPeran = [];
        $unitTarget = mb_strtolower(trim((string) ($d->nama_unit_kerja ?? '')));
        foreach ($roles as $r) {
            $peran = $r['peran'];
            $rUnit = mb_strtolower(trim((string) ($r['unit_label'] ?? '')));
            if (!isset($byPeran[$peran])) {
                $byPeran[$peran] = $r['levels'];
            }
            if ($unitTarget !== '' && ($rUnit === $unitTarget || str_contains($rUnit, $unitTarget) || str_contains($unitTarget, $rUnit))) {
                $byPeran[$peran] = $r['levels'];
            }
        }

        $employees = $d->employees->map(function ($e) use ($byPeran, $competencies) {
            $levels = array_values($e->levels ?? []);
            $standard = $byPeran[$e->peran] ?? null;
            $gap = [];
            foreach ($competencies as $i => $c) {
                $ev = $levels[$i] ?? null;
                $sv = $standard[$i] ?? null;
                $gap[] = ($ev === null || $sv === null) ? null : ((int) $ev - (int) $sv);
            }
            return [
                'id' => $e->id,
                'aset_sdm_id' => $e->aset_sdm_id,
                'nama' => $e->nama,
                'nip' => $e->nip,
                'jabatan' => $e->jabatan,
                'peran' => $e->peran,
                'levels' => $levels,
                'gap' => $gap,
                'usulan_training' => $e->usulan_training,
            ];
        })->values();

        return [
            'id' => $d->id,
            'nomor_dokumen' => $d->nomor_dokumen,
            'no_revisi' => $d->no_revisi,
            'tanggal_berlaku' => $d->tanggal_berlaku?->format('Y-m-d'),
            'nama_unit_kerja' => $d->nama_unit_kerja,
            'competencies' => $competencies,
            'roles' => $roles,
            'employees' => $employees,
            'trainings' => $d->trainings->map(fn ($t) => [
                'id' => $t->id, 'nama' => $t->nama,
                'jabatan' => $t->jabatan, 'usulan_training' => $t->usulan_training,
            ])->values(),
            'created_at' => $d->created_at?->toIso8601String(),
            'updated_at' => $d->updated_at?->toIso8601String(),
        ];
    }
}
