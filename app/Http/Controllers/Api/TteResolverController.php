<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Pegawai;
use App\Services\TteResolverService;
use Illuminate\Http\Request;

/**
 * Menentukan spesimen TTE untuk sebuah dokumen.
 *
 * Sumber data tunggal: master pegawai (tabel pegawai).
 * Penanda tangan dicocokkan lewat NIP, dengan nama sebagai cadangan.
 */
class TteResolverController extends Controller
{
    public function __construct(private TteResolverService $resolver)
    {
    }

    /**
     * GET /api/tte/options
     * Daftar pilihan penanda tangan (langsung dari data pegawai).
     */
    public function options()
    {
        $items = Pegawai::query()
            ->orderBy('nama')
            ->get(['id', 'nama', 'nip', 'jabatan'])
            ->map(fn ($p) => [
                'key' => 'pegawai:' . $p->id,
                'source' => 'pegawai',
                'id' => $p->id,
                'nama' => $p->nama,
                'nip' => $p->nip,
                'jabatan' => $p->jabatan,
                'unit' => null,
                'label' => $p->nama . ($p->nip ? ' — ' . $p->nip : ''),
            ])->values();

        return response()->json([
            'success' => true,
            'message' => 'Daftar penanda tangan berhasil diambil.',
            'data' => $items,
        ], 200);
    }

    /**
     * GET /api/tte/resolve?nip=...&nama=...
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

        $pegawai = $this->resolver->find($nip, $nama);

        if ($pegawai === null) {
            return response()->json([
                'success' => true,
                'data' => null,
                'message' => 'Tidak ada pegawai yang cocok.',
            ], 200);
        }

        return response()->json([
            'success' => true,
            'message' => 'Spesimen ditemukan.',
            'data' => [
                'source' => 'pegawai',
                'id' => $pegawai->id,
                'nama' => $pegawai->nama,
                'nip' => $pegawai->nip,
                'jabatan' => $pegawai->jabatan,
                'unit' => null,
            ],
        ], 200);
    }

    /**
     * GET /api/tte/resolve/image?nip=...&nama=...
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
}