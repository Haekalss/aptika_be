<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Pegawai;
use App\Models\TteSpesimen;
use App\Services\TteSpecimenService;
use Illuminate\Http\Request;

/**
 * Menentukan spesimen TTE mana yang dipakai pada sebuah dokumen.
 *
 * Urutan pencarian:
 *   1. Master Spesimen TTE (tabel tte_spesimens) — dicocokkan lewat NIP, lalu nama.
 *   2. Fallback ke master pegawai (tabel pegawai).
 *
 * Dengan begitu, data yang ditambahkan di menu Data Master → Spesimen TTE
 * langsung terpakai saat mencetak dokumen.
 */
class TteResolverController extends Controller
{
    public function __construct(private TteSpecimenService $tte)
    {
    }

    /**
     * GET /api/tte/resolve?nip=...&nama=...
     * Mengembalikan sumber gambar yang harus dipakai.
     */
    public function resolve(Request $request)
    {
        $nip = trim((string) $request->query('nip', ''));
        $nama = trim((string) $request->query('nama', ''));

        if ($nip === '' && $nama === '') {
            return response()->json([
                'success' => false,
                'message' => 'Parameter nip atau nama wajib diisi.',
            ], 422);
        }

        $match = $this->findSpesimen($nip, $nama);

        if ($match === null) {
            return response()->json([
                'success' => true,
                'data' => null,
                'message' => 'Tidak ada spesimen yang cocok.',
            ], 200);
        }

        return response()->json([
            'success' => true,
            'message' => 'Spesimen ditemukan.',
            'data' => $match,
        ], 200);
    }

    /**
     * GET /api/tte/resolve/image?nip=...&nama=...
     * Mengembalikan gambar PNG langsung (dipakai <img> lewat blob).
     */
    public function image(Request $request)
    {
        $nip = trim((string) $request->query('nip', ''));
        $nama = trim((string) $request->query('nama', ''));

        $match = $this->findSpesimen($nip, $nama);

        if ($match === null) {
            return response()->json([
                'success' => false,
                'message' => 'Spesimen tidak ditemukan.',
            ], 404);
        }

        $source = $match['source'] === 'master'
            ? TteSpesimen::find($match['id'])
            : Pegawai::find($match['id']);

        if (!$source) {
            return response()->json([
                'success' => false,
                'message' => 'Sumber data spesimen hilang.',
            ], 404);
        }

        $path = $this->tte->generate($source, $match['unit'] ?? null);

        if ($path === null) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal membuat gambar spesimen.',
            ], 500);
        }

        return response()->file($path, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }

    /** Cari spesimen: master dulu, baru pegawai. */
    private function findSpesimen(string $nip, string $nama): ?array
    {
        // 1) Master Spesimen TTE (aktif saja)
        $master = TteSpesimen::query()
            ->where('is_active', true)
            ->when($nip !== '', fn ($q) => $q->where('nip', $nip))
            ->when($nip === '' && $nama !== '', fn ($q) => $q->where('nama', 'like', "%{$nama}%"))
            ->first();

        // bila pencarian NIP gagal, coba nama
        if (!$master && $nip !== '' && $nama !== '') {
            $master = TteSpesimen::query()
                ->where('is_active', true)
                ->where('nama', 'like', "%{$nama}%")
                ->first();
        }

        if ($master) {
            return [
                'source' => 'master',
                'id' => $master->id,
                'nama' => $master->nama,
                'nip' => $master->nip,
                'jabatan' => $master->jabatan,
                'unit' => $master->unit,
            ];
        }

        // 2) Fallback ke master pegawai
        $pegawai = Pegawai::query()
            ->when($nip !== '', fn ($q) => $q->where('nip', $nip))
            ->when($nip === '' && $nama !== '', fn ($q) => $q->where('nama', 'like', "%{$nama}%"))
            ->first();

        if (!$pegawai && $nip !== '' && $nama !== '') {
            $pegawai = Pegawai::query()->where('nama', 'like', "%{$nama}%")->first();
        }

        if ($pegawai) {
            return [
                'source' => 'pegawai',
                'id' => $pegawai->id,
                'nama' => $pegawai->nama,
                'nip' => $pegawai->nip,
                'jabatan' => $pegawai->jabatan,
                'unit' => null,
            ];
        }

        return null;
    }
}
