<?php

namespace App\Http\Controllers\Smki;

use App\Http\Controllers\Controller;
use App\Models\MasterUnitKerja;
use App\Models\SmkiAsetSdm;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SmkiAsetSdmController extends Controller
{
    /**
     * Daftar aset SDM dengan search, filter, statistik, dan pagination.
     */
    public function index(Request $request)
    {
        $nameCol = SmkiAsetSdm::UNIT_NAME_COLUMN;

        $query = SmkiAsetSdm::with(['pelatihans', 'unitKerja'])->orderByDesc('id');

        if ($request->filled('search')) {
            $search = trim($request->input('search'));

            $query->where(function ($q) use ($search, $nameCol) {
                $q->where('nama', 'like', "%{$search}%")
                    ->orWhere('nip', 'like', "%{$search}%")
                    ->orWhere('jabatan', 'like', "%{$search}%")
                    ->orWhere('jabatan_smki', 'like', "%{$search}%")
                    ->orWhereHas('unitKerja', function ($u) use ($search, $nameCol) {
                        $u->where($nameCol, 'like', "%{$search}%");
                    });

                // Pencarian kode aset, mis. "SDM-001" atau "sdm1" => id 1
                if (preg_match('/^sdm-?0*(\d+)$/i', $search, $m)) {
                    $q->orWhere('id', (int) $m[1]);
                }
            });
        }

        if ($request->filled('pangkat_golongan')) {
            $query->where('pangkat_golongan', $request->input('pangkat_golongan'));
        }

        if ($request->filled('jabatan')) {
            $query->where('jabatan', $request->input('jabatan'));
        }

        if ($request->filled('unit_kerja_id')) {
            $query->where('unit_kerja_id', $request->input('unit_kerja_id'));
        }

        $perPage = (int) $request->input('per_page', 10);

        if ($perPage === -1) {
            $items = $query->get();
            $meta = [
                'current_page' => 1,
                'last_page'    => 1,
                'per_page'     => $items->count(),
                'total'        => $items->count(),
            ];
        } else {
            $paginated = $query->paginate(max(1, $perPage));
            $items = collect($paginated->items());
            $meta = [
                'current_page' => $paginated->currentPage(),
                'last_page'    => $paginated->lastPage(),
                'per_page'     => $paginated->perPage(),
                'total'        => $paginated->total(),
            ];
        }

        return response()->json([
            'success' => true,
            'data'    => $items->map(fn ($i) => $this->transform($i))->values(),
            'stats'   => [
                'total_aset'    => SmkiAsetSdm::count(),
                'total_jabatan' => SmkiAsetSdm::distinct()->count('jabatan'),
                'total_unit'    => SmkiAsetSdm::distinct()->count('unit_kerja_id'),
            ],
            'meta'    => $meta,
        ]);
    }

    /**
     * Opsi dropdown.
     * - Default: hanya nilai yang benar-benar ada di data (untuk filter halaman list).
     * - ?scope=all: unit_kerja berisi SEMUA master unit kerja (untuk form tambah/ubah).
     */
    public function lookup(Request $request)
    {
        $nameCol = SmkiAsetSdm::UNIT_NAME_COLUMN;

        $pangkat = SmkiAsetSdm::query()
            ->whereNotNull('pangkat_golongan')
            ->distinct()
            ->orderBy('pangkat_golongan')
            ->pluck('pangkat_golongan');

        $jabatan = SmkiAsetSdm::query()
            ->whereNotNull('jabatan')
            ->distinct()
            ->orderBy('jabatan')
            ->pluck('jabatan');

        $unitQuery = MasterUnitKerja::query();

        if ($request->input('scope') !== 'all') {
            $unitQuery->whereIn('id_unit_kerja', SmkiAsetSdm::query()->select('unit_kerja_id'));
        }

        $unit = $unitQuery
            ->orderBy($nameCol)
            ->get(["id_unit_kerja as id", "{$nameCol} as nama"]);

        return response()->json([
            'success' => true,
            'data'    => [
                'pangkat_golongan' => $pangkat,
                'jabatan'          => $jabatan,
                'unit_kerja'       => $unit,
            ],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $this->validatePayload($request);

        $asetSdm = DB::transaction(function () use ($validated) {
            $asetSdm = SmkiAsetSdm::create(array_merge(
                collect($validated)->except('pelatihan')->toArray(),
                ['created_by' => auth()->id()]
            ));

            $this->syncPelatihan($asetSdm, $validated['pelatihan'] ?? []);

            return $asetSdm;
        });

        return response()->json([
            'success' => true,
            'message' => 'Data aset SDM baru berhasil ditambahkan!',
            'data'    => $this->transform($asetSdm->load(['pelatihans', 'unitKerja'])),
        ], 201);
    }

    public function show($id)
    {
        $asetSdm = SmkiAsetSdm::with(['pelatihans', 'unitKerja'])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data'    => $this->transform($asetSdm),
        ]);
    }

    public function update(Request $request, $id)
    {
        $asetSdm = SmkiAsetSdm::findOrFail($id);
        $validated = $this->validatePayload($request, $asetSdm->id);

        DB::transaction(function () use ($asetSdm, $validated) {
            $asetSdm->update(collect($validated)->except('pelatihan')->toArray());
            $asetSdm->pelatihans()->delete();
            $this->syncPelatihan($asetSdm, $validated['pelatihan'] ?? []);
        });

        return response()->json([
            'success' => true,
            'message' => 'Data aset SDM berhasil diperbarui!',
            'data'    => $this->transform($asetSdm->fresh(['pelatihans', 'unitKerja'])),
        ]);
    }

    /**
     * Pelatihan ikut terhapus lewat cascadeOnDelete di migration.
     */
    public function destroy($id)
    {
        SmkiAsetSdm::findOrFail($id)->delete();

        return response()->json([
            'success' => true,
            'message' => 'Data aset SDM berhasil dihapus!',
        ]);
    }

    private function syncPelatihan(SmkiAsetSdm $asetSdm, array $pelatihan): void
    {
        foreach ($pelatihan as $item) {
            $asetSdm->pelatihans()->create([
                'nama_pelatihan' => $item['nama'],
                'tahun'          => $item['tahun'],
            ]);
        }
    }

    /**
     * Bentuk respons yang dipakai frontend.
     */
    private function transform(SmkiAsetSdm $i): array
    {
        $nameCol = SmkiAsetSdm::UNIT_NAME_COLUMN;

        return [
            'id'                => $i->id,
            'kode_aset'         => $i->kode_aset,
            'nama'              => $i->nama,
            'nip'               => $i->nip,
            'pangkat_golongan'  => $i->pangkat_golongan,
            'jabatan'           => $i->jabatan,
            'tmt_jabatan'       => $i->tmt_jabatan?->format('Y-m-d'),
            'tmt_jabatan_label' => $i->tmt_jabatan?->locale('id')->translatedFormat('d F Y'),
            'unit_kerja_id'     => $i->unit_kerja_id,
            'unit_kerja'        => $i->unitKerja?->{$nameCol} ?? '-',
            'jabatan_smki'      => $i->jabatan_smki,
            'tanggung_jawab'    => $i->tanggung_jawab,
            'jenjang'           => $i->jenjang,
            'jurusan'           => $i->jurusan,
            'tahun_lulus'       => (string) $i->tahun_lulus,
            'pelatihan'         => $i->pelatihans->map(fn ($p) => [
                'id'    => $p->id,
                'nama'  => $p->nama_pelatihan,
                'tahun' => (string) $p->tahun,
            ])->values(),
        ];
    }

    private function validatePayload(Request $request, ?int $ignoreId = null): array
    {
        $year = (int) now()->year;

        return $request->validate([
            'nama'              => ['required', 'string', 'max:255'],
            'nip'               => [
                'required', 'string', 'regex:/^\d{18}$/',
                Rule::unique('smki_aset_sdms', 'nip')->ignore($ignoreId),
            ],
            'pangkat_golongan'  => ['required', 'string', 'max:255'],
            'jabatan'           => ['required', 'string', 'max:255'],
            'tmt_jabatan'       => ['nullable', 'date'],
            'unit_kerja_id'     => ['required', 'exists:master_unit_kerja,id_unit_kerja'],
            'jabatan_smki'      => ['required', 'string', 'max:255'],
            'tanggung_jawab'    => ['required', 'string'],
            'jenjang'           => ['required', 'in:SMA/SMK,D3,D4,S1,S2,S3'],
            'jurusan'           => ['required', 'string', 'max:255'],
            'tahun_lulus'       => ['required', 'integer', 'digits:4', 'min:1950', "max:{$year}"],
            'pelatihan'         => ['nullable', 'array'],
            'pelatihan.*.nama'  => ['required_with:pelatihan', 'string', 'max:255'],
            'pelatihan.*.tahun' => ['required_with:pelatihan', 'integer', 'digits:4', 'min:1950', "max:{$year}"],
        ], $this->validationMessages(), $this->validationAttributes());
    }

    private function validationMessages(): array
    {
        return [
            'required'      => ':attribute wajib diisi.',
            'required_with' => ':attribute wajib diisi.',
            'string'        => ':attribute harus berupa teks.',
            'integer'       => ':attribute harus berupa angka.',
            'digits'        => ':attribute harus :digits digit.',
            'date'          => ':attribute bukan tanggal yang valid.',
            'exists'        => ':attribute tidak valid.',
            'in'            => 'Pilihan :attribute tidak valid.',
            'unique'        => ':attribute sudah terdaftar.',
            'array'         => ':attribute tidak valid.',
            'max.string'    => ':attribute maksimal :max karakter.',
            'min.numeric'   => ':attribute minimal :min.',
            'max.numeric'   => ':attribute maksimal :max.',
            'nip.regex'     => 'NIP harus 18 digit angka.',
        ];
    }

    private function validationAttributes(): array
    {
        return [
            'nama'              => 'Nama',
            'nip'               => 'NIP',
            'pangkat_golongan'  => 'Pangkat / golongan',
            'jabatan'           => 'Jabatan',
            'tmt_jabatan'       => 'TMT jabatan',
            'unit_kerja_id'     => 'Unit kerja',
            'jabatan_smki'      => 'Jabatan di SMKI',
            'tanggung_jawab'    => 'Tanggung jawab',
            'jenjang'           => 'Jenjang',
            'jurusan'           => 'Jurusan',
            'tahun_lulus'       => 'Tahun lulus',
            'pelatihan'         => 'Pelatihan',
            'pelatihan.*.nama'  => 'Nama pelatihan',
            'pelatihan.*.tahun' => 'Tahun pelatihan',
        ];
    }
}