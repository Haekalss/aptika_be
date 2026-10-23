<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Pegawai;
use App\Models\TteSpesimen;
use App\Services\TteResolverService;
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
    public function __construct(private TteResolverService $resolver)
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

        $match = $this->resolver->find($nip, $nama);

        if ($match === null) {
            return response()->json([
                'success' => true,
                'data' => null,
                'message' => 'Tidak ada spesimen yang cocok.',
            ], 200);
        }

        $model = $match['model'];

        return response()->json([
            'success' => true,
            'message' => 'Spesimen ditemukan.',
            'data' => [
                'source' => $match['source'],
                'id' => $model->id,
                'nama' => $model->nama,
                'nip' => $model->nip,
                'jabatan' => $model->jabatan,
                'unit' => $model->unit ?? null,
            ],
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

        $path = $this->resolver->imagePath($nip, $nama);

        if ($path === null) {
            return response()->json([
                'success' => false,
                'message' => 'Spesimen tidak ditemukan.',
            ], 404);
        }

        return response()->file($path, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }

    /**
     * GET /api/tte/options
     * Daftar pilihan penanda tangan untuk dropdown saat cetak/unduh.
     * Master spesimen lebih dulu, lalu pegawai yang belum punya spesimen.
     */
    public function options()
    {
        $master = TteSpesimen::query()
            ->where('is_active', true)
            ->orderBy('nama')
            ->get(['id', 'nama', 'nip', 'jabatan', 'unit']);

        $nipMaster = $master->pluck('nip')->filter()->all();

        $pegawai = Pegawai::query()
            ->orderBy('nama')
            ->get(['id', 'nama', 'nip', 'jabatan'])
            ->reject(fn ($p) => $p->nip !== null && in_array($p->nip, $nipMaster, true))
            ->values();

        $items = $master->map(fn ($m) => [
            'key' => 'master:' . $m->id,
            'source' => 'master',
            'id' => $m->id,
            'nama' => $m->nama,
            'nip' => $m->nip,
            'jabatan' => $m->jabatan,
            'unit' => $m->unit,
            'label' => $m->nama . ($m->nip ? ' — ' . $m->nip : ''),
        ])->concat($pegawai->map(fn ($p) => [
            'key' => 'pegawai:' . $p->id,
            'source' => 'pegawai',
            'id' => $p->id,
            'nama' => $p->nama,
            'nip' => $p->nip,
            'jabatan' => $p->jabatan,
            'unit' => null,
            'label' => $p->nama . ($p->nip ? ' — ' . $p->nip : ''),
        ]))->values();

        return response()->json([
            'success' => true,
            'message' => 'Daftar penanda tangan berhasil diambil.',
            'data' => $items,
        ], 200);
    }

    /** Cari spesimen: master dulu, baru pegawai. */
    private function findSpesimen(string $nip, string $nama): ?array
    {
        // Delegasi ke service agar konsisten dengan ekspor DOCX.
        return $this->resolver->find($nip, $nama);
    }
}
