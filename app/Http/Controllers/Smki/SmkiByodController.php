<?php

namespace App\Http\Controllers\Smki;

use App\Http\Controllers\Controller;
use App\Models\SmkiByodDevice;
use App\Models\SmkiByodForm;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Formulir Bring Your Own Device / Penggunaan Perangkat Pribadi (FR-045/KOM.03/05/SANDIKAMI).
 */
class SmkiByodController extends Controller
{
    /**
     * Daftar formulir + pencarian/filter + ringkasan statistik.
     */
    public function index(Request $request)
    {
        $query = SmkiByodForm::query()
            ->withCount([
                'perangkat as total_perangkat',
                'perangkat as laptop_count' => fn($q) => $q->where('jenis_perangkat', 'Laptop'),
                'perangkat as pc_count'     => fn($q) => $q->where('jenis_perangkat', 'PC'),
                'perangkat as tab_count'    => fn($q) => $q->where('jenis_perangkat', 'Tab'),
            ])
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc');

        if ($request->filled('search')) {
            $search = trim($request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('nama', 'like', "%{$search}%")
                  ->orWhere('nip', 'like', "%{$search}%")
                  ->orWhere('unit_kerja', 'like', "%{$search}%")
                  ->orWhereHas('perangkat', function ($i) use ($search) {
                      $i->where('nama', 'like', "%{$search}%")
                        ->orWhere('nip', 'like', "%{$search}%")
                        ->orWhere('merek_tipe', 'like', "%{$search}%")
                        ->orWhere('nomor_seri', 'like', "%{$search}%");
                  });
            });
        }

        if ($request->filled('jenis_perangkat')) {
            $jenis = $request->input('jenis_perangkat');
            $query->whereHas('perangkat', fn($i) => $i->where('jenis_perangkat', $jenis));
        }

        $perPage = (int) $request->input('per_page', 10);
        if ($perPage === -1) {
            $items = $query->get();
            $paginated = null;
        } else {
            $paginated = $query->paginate($perPage);
            $items = $paginated->items();
        }

        // Statistik mengikuti scope bidang yang sama (BelongsToBidang) dengan daftar.
        $formIds = SmkiByodForm::query()->pluck('id');
        $deviceBase = SmkiByodDevice::query()->whereIn('byod_form_id', $formIds);

        return response()->json([
            'success' => true,
            'data'    => $items,
            'stats'   => [
                'total_formulir' => $formIds->count(),
                'total_perangkat' => (clone $deviceBase)->count(),
                'laptop'         => (clone $deviceBase)->where('jenis_perangkat', 'Laptop')->count(),
                'pc'             => (clone $deviceBase)->where('jenis_perangkat', 'PC')->count(),
                'tab'            => (clone $deviceBase)->where('jenis_perangkat', 'Tab')->count(),
            ],
            'meta' => $paginated ? [
                'current_page' => $paginated->currentPage(),
                'last_page'    => $paginated->lastPage(),
                'per_page'     => $paginated->perPage(),
                'total'        => $paginated->total(),
            ] : ['total' => count($items)],
        ]);
    }

    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'nama'              => 'required|string|max:255',
            'nip'               => 'nullable|string|max:50',
            'jabatan'           => 'required|string|max:255',
            'unit_kerja'        => 'required|string|max:255',
            'ditandatangani_di' => 'nullable|string|max:255',
            'tanggal_ttd'       => 'nullable|date',
            'perangkat'                    => 'required|array|min:1',
            'perangkat.*.nama'             => 'required|string|max:255',
            'perangkat.*.nip'              => 'nullable|string|max:50',
            'perangkat.*.jenis_perangkat'  => 'required|in:Laptop,PC,Tab',
            'perangkat.*.merek_tipe'       => 'required|string|max:255',
            'perangkat.*.nomor_seri'       => 'required|string|max:255',
        ], [
            'nama.required'       => 'Nama wajib diisi.',
            'jabatan.required'    => 'Jabatan wajib diisi.',
            'unit_kerja.required' => 'Unit Kerja wajib diisi.',
            'perangkat.required'  => 'Minimal satu perangkat pribadi harus diisi.',
            'perangkat.min'       => 'Minimal satu perangkat pribadi harus diisi.',
            'perangkat.*.nama.required'            => 'Nama pemilik perangkat wajib diisi.',
            'perangkat.*.jenis_perangkat.required' => 'Jenis perangkat TI wajib dipilih.',
            'perangkat.*.jenis_perangkat.in'       => 'Jenis perangkat TI harus Laptop, PC, atau Tab.',
            'perangkat.*.merek_tipe.required'      => 'Merek/tipe wajib diisi.',
            'perangkat.*.nomor_seri.required'      => 'Nomor seri wajib diisi.',
        ]);
    }

    private function headerData(array $validated): array
    {
        return collect($validated)->except('perangkat')->all();
    }

    private function syncPerangkat(SmkiByodForm $form, array $rows): void
    {
        $form->perangkat()->delete();
        foreach ($rows as $row) {
            $form->perangkat()->create([
                'nama'            => trim($row['nama']),
                'nip'             => isset($row['nip']) ? trim($row['nip']) : null,
                'jenis_perangkat' => $row['jenis_perangkat'],
                'merek_tipe'      => trim($row['merek_tipe']),
                'nomor_seri'      => trim($row['nomor_seri']),
            ]);
        }
    }

    public function store(Request $request)
    {
        $validated = $this->validatePayload($request);

        $form = DB::transaction(function () use ($validated) {
            $form = SmkiByodForm::create($this->headerData($validated) + [
                'user_id'   => auth()->id(),
                'bidang_id' => auth()->user()?->bidang_id,
            ]);
            $this->syncPerangkat($form, $validated['perangkat']);
            return $form;
        });

        return response()->json([
            'success' => true,
            'message' => 'Formulir penggunaan perangkat pribadi berhasil disimpan!',
            'data'    => $form->load('perangkat'),
        ], 201);
    }

    public function show($id)
    {
        $form = SmkiByodForm::with(['perangkat', 'user:id,name,email'])->findOrFail($id);

        return response()->json(['success' => true, 'data' => $form]);
    }

    public function update(Request $request, $id)
    {
        $form = SmkiByodForm::findOrFail($id);
        $validated = $this->validatePayload($request);

        DB::transaction(function () use ($form, $validated) {
            $form->update($this->headerData($validated));
            $this->syncPerangkat($form, $validated['perangkat']);
        });

        return response()->json([
            'success' => true,
            'message' => 'Formulir penggunaan perangkat pribadi berhasil diperbarui!',
            'data'    => $form->load('perangkat'),
        ]);
    }

    public function destroy($id)
    {
        $form = SmkiByodForm::findOrFail($id);
        $form->delete();

        return response()->json(['success' => true, 'message' => 'Formulir penggunaan perangkat pribadi berhasil dihapus!']);
    }
}
