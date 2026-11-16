<?php

namespace App\Http\Controllers\Smki;

use App\Http\Controllers\Controller;
use App\Http\Requests\Smki\PemantauanKonfigurasi\MasterJenisRequest;
use App\Http\Requests\Smki\PemantauanKonfigurasi\MasterObjekRequest;
use App\Http\Requests\Smki\PemantauanKonfigurasi\NilaiStandarRequest;
use App\Models\SmkiJenisKonfigurasi;
use App\Models\SmkiNilaiStandar;
use App\Models\SmkiObjek;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Data Master FR-048 — Jenis Konfigurasi, Objek, Nilai Standar.
 * Khusus admin (middleware role:admin di route). Tidak ada delete permanen — hanya isActive.
 */
class SmkiMasterKonfigurasiController extends Controller
{
    // ───────────────────────────── Jenis Konfigurasi ─────────────────────────────
    public function jenisIndex(): JsonResponse
    {
        $data = SmkiJenisKonfigurasi::orderBy('nama')->get()->map(fn ($j) => $this->jenisResource($j));

        return $this->respond(['success' => true, 'data' => $data]);
    }

    public function jenisStore(MasterJenisRequest $request): JsonResponse
    {
        $j = SmkiJenisKonfigurasi::create([
            'nama' => trim($request->input('nama')),
            'keterangan' => $request->input('keterangan'),
            'is_active' => $request->boolean('isActive', true),
        ]);

        return $this->respond(['success' => true, 'message' => 'Jenis Konfigurasi ditambahkan.', 'data' => $this->jenisResource($j)], 201);
    }

    public function jenisUpdate(MasterJenisRequest $request, int|string $id): JsonResponse
    {
        $j = SmkiJenisKonfigurasi::findOrFail($id);
        $j->update([
            'nama' => trim($request->input('nama')),
            'keterangan' => $request->input('keterangan'),
            'is_active' => $request->boolean('isActive'),
        ]);

        return $this->respond(['success' => true, 'message' => 'Jenis Konfigurasi disimpan.', 'data' => $this->jenisResource($j)]);
    }

    // ───────────────────────────── Objek ─────────────────────────────
    public function objekIndex(): JsonResponse
    {
        $data = SmkiObjek::with('jenis')->orderBy('nama')->get()->map(fn ($o) => $this->objekResource($o));

        return $this->respond(['success' => true, 'data' => $data]);
    }

    public function objekStore(MasterObjekRequest $request): JsonResponse
    {
        $o = SmkiObjek::create([
            'jenis_konfigurasi_id' => $request->integer('jenisKonfigurasiId'),
            'nama' => trim($request->input('nama')),
            'is_active' => $request->boolean('isActive', true),
        ]);

        return $this->respond(['success' => true, 'message' => 'Objek ditambahkan.', 'data' => $this->objekResource($o->load('jenis'))], 201);
    }

    public function objekUpdate(MasterObjekRequest $request, int|string $id): JsonResponse
    {
        $o = SmkiObjek::findOrFail($id);
        $o->update([
            'jenis_konfigurasi_id' => $request->integer('jenisKonfigurasiId'),
            'nama' => trim($request->input('nama')),
            'is_active' => $request->boolean('isActive'),
        ]);

        return $this->respond(['success' => true, 'message' => 'Objek disimpan.', 'data' => $this->objekResource($o->load('jenis'))]);
    }

    // ───────────────────────────── Nilai Standar ─────────────────────────────
    public function nilaiStandarIndex(\Illuminate\Http\Request $request): JsonResponse
    {
        $query = SmkiNilaiStandar::with('objek');
        if ($request->filled('objek_id')) {
            $query->where('objek_id', $request->integer('objek_id'));
        }
        $data = $query->orderByDesc('is_active')->orderBy('nama_konfigurasi')->get()
            ->map(fn ($n) => $this->nilaiResource($n));

        return $this->respond(['success' => true, 'data' => $data]);
    }

    public function nilaiStandarUpdate(NilaiStandarRequest $request, int|string $objekId): JsonResponse
    {
        $objek = SmkiObjek::findOrFail($objekId);
        $rows = (array) $request->input('rows', []);
        $created = [];

        DB::transaction(function () use ($objek, $rows, &$created) {
            // Baris lama objek ini dinonaktifkan — perubahan hanya berlaku untuk pemantauan baru
            SmkiNilaiStandar::where('objek_id', $objek->id)->update(['is_active' => false]);

            $now = now();
            foreach ($rows as $row) {
                $created[] = SmkiNilaiStandar::create([
                    'objek_id' => $objek->id,
                    'nama_konfigurasi' => trim($row['namaKonfigurasi']),
                    'nilai_standar' => trim($row['nilaiStandar']),
                    'is_active' => true,
                ]);
            }
        });

        $data = collect($created)->map(fn ($n) => $this->nilaiResource($n->load('objek')));

        return $this->respond(['success' => true, 'message' => 'Nilai standar tersimpan (berlaku untuk pemantauan berikutnya).', 'data' => $data]);
    }

    // ───────────────────────────── Helpers ─────────────────────────────
    private function jenisResource(SmkiJenisKonfigurasi $j): array
    {
        return [
            'id' => $j->id,
            'nama' => $j->nama,
            'keterangan' => $j->keterangan,
            'isActive' => (bool) $j->is_active,
        ];
    }

    private function objekResource(SmkiObjek $o): array
    {
        return [
            'id' => $o->id,
            'jenisKonfigurasiId' => $o->jenis_konfigurasi_id,
            'jenisKonfigurasiNama' => $o->jenis?->nama,
            'nama' => $o->nama,
            'isActive' => (bool) $o->is_active,
        ];
    }

    private function nilaiResource(SmkiNilaiStandar $n): array
    {
        return [
            'id' => $n->id,
            'objekId' => $n->objek_id,
            'objekNama' => $n->objek?->nama,
            'namaKonfigurasi' => $n->nama_konfigurasi,
            'nilaiStandar' => $n->nilai_standar,
            'isActive' => (bool) $n->is_active,
        ];
    }

    private function respond(array $payload, int $status = 200): JsonResponse
    {
        return response()->json($payload, $status);
    }
}
