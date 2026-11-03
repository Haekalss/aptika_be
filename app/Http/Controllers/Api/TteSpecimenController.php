<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Pegawai;
use App\Services\TteSpecimenService;
use Illuminate\Http\Request;

/**
 * Spesimen TTE (Tanda Tangan Elektronik) yang dihasilkan dari data master pegawai.
 *
 * Dipakai oleh halaman cetak dokumen: setiap dokumen yang mencetak blok tanda tangan
 * mengambil gambar dari sini, sehingga tidak perlu lagi file PNG statis per pejabat.
 */
class TteSpecimenController extends Controller
{
    public function __construct(private TteSpecimenService $tte)
    {
    }

    /** Daftar pegawai yang bisa dipakai sebagai penanda tangan. */
    public function pegawai()
    {
        $items = Pegawai::orderBy('nama')->get(['id', 'nama', 'nip', 'pangkat', 'jabatan', 'role']);

        return response()->json([
            'success' => true,
            'message' => 'Daftar penanda tangan berhasil diambil.',
            'data' => $items,
        ], 200);
    }

    /**
     * Gambar TTE untuk satu pegawai.
     * GET /api/tte/spesimen/{pegawai}?unit=DINAS%20KOMUNIKASI...
     */
    public function show(Request $request, $pegawai)
    {
        $model = Pegawai::find($pegawai);

        if (!$model) {
            return response()->json([
                'success' => false,
                'message' => 'Data pegawai tidak ditemukan.',
            ], 404);
        }

        $unit = $request->query('unit');
        $path = $this->tte->generate($model, $unit ? (string) $unit : null);

        if ($path === null) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal membuat spesimen TTE. Pastikan ekstensi GD aktif.',
            ], 500);
        }

        return response()->file($path, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    /**
     * Data TTE dalam bentuk JSON, dipakai frontend untuk merender blok tanda tangan
     * langsung di halaman cetak (tanpa perlu mengunduh gambar).
     * GET /api/tte/data/{pegawai}
     */
    public function data(Request $request, $pegawai)
    {
        $model = Pegawai::find($pegawai);

        if (!$model) {
            return response()->json([
                'success' => false,
                'message' => 'Data pegawai tidak ditemukan.',
            ], 404);
        }

        $unit = $request->query('unit') ?: 'DINAS KOMUNIKASI DAN INFORMATIKA';
        $pangkat = trim((string) $model->pangkat);
        $golongan = '';
        if (str_contains($pangkat, '/')) {
            [$pangkat, $golongan] = array_map('trim', explode('/', $pangkat, 2));
        }

        return response()->json([
            'success' => true,
            'message' => 'Data TTE berhasil diambil.',
            'data' => [
                'pegawai_id' => $model->id,
                'nama' => $model->nama,
                'nip' => $model->nip,
                'jabatan' => mb_strtoupper((string) $model->jabatan),
                'unit' => mb_strtoupper($unit),
                'pangkat' => $pangkat,
                'golongan' => $golongan,
                'kalimat' => 'Ditandatangani secara elektronik oleh:',
                'gambar_url' => url('/api/tte/spesimen/' . $model->id),
            ],
        ], 200);
    }
}
