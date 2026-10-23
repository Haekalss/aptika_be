<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TteSpesimen;
use App\Services\TteSpecimenService;
use Illuminate\Http\Request;

/**
 * Master Spesimen TTE — CRUD data penanda tangan.
 *
 * Data di sini dipakai untuk mencetak blok tanda tangan elektronik pada dokumen.
 * Gambar tidak disimpan sebagai file; selalu di-generate dari data agar selalu
 * sinkron saat data diubah.
 */
class TteSpesimenController extends Controller
{
    public function __construct(private TteSpecimenService $tte)
    {
    }

    public function index(Request $request)
    {
        $q = trim((string) $request->query('search', ''));

        $items = TteSpesimen::query()
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($sub) use ($q) {
                    $sub->where('nama', 'like', "%{$q}%")
                        ->orWhere('nip', 'like', "%{$q}%")
                        ->orWhere('jabatan', 'like', "%{$q}%")
                        ->orWhere('unit', 'like', "%{$q}%");
                });
            })
            ->orderByDesc('is_active')
            ->orderBy('nama')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Data spesimen TTE berhasil diambil.',
            'data' => $items,
        ], 200);
    }

    public function store(Request $request)
    {
        $validated = $this->validatePayload($request);

        $item = TteSpesimen::create($validated + [
            'unit' => $validated['unit'] ?? 'DINAS KOMUNIKASI DAN INFORMATIKA',
            'kalimat' => $validated['kalimat'] ?? 'Ditandatangani secara elektronik oleh:',
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Spesimen TTE berhasil ditambahkan.',
            'data' => $item,
        ], 201);
    }

    public function show($id)
    {
        $item = TteSpesimen::find($id);

        if (!$item) {
            return response()->json([
                'success' => false,
                'message' => 'Spesimen TTE tidak ditemukan.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Detail spesimen TTE berhasil diambil.',
            'data' => $item,
        ], 200);
    }

    public function update(Request $request, $id)
    {
        $item = TteSpesimen::find($id);

        if (!$item) {
            return response()->json([
                'success' => false,
                'message' => 'Spesimen TTE tidak ditemukan.',
            ], 404);
        }

        $item->update($this->validatePayload($request, $id));

        return response()->json([
            'success' => true,
            'message' => 'Spesimen TTE berhasil diperbarui.',
            'data' => $item->fresh(),
        ], 200);
    }

    public function destroy($id)
    {
        $item = TteSpesimen::find($id);

        if (!$item) {
            return response()->json([
                'success' => false,
                'message' => 'Spesimen TTE tidak ditemukan.',
            ], 404);
        }

        $item->delete();

        return response()->json([
            'success' => true,
            'message' => 'Spesimen TTE berhasil dihapus.',
        ], 200);
    }

    /** Preview gambar dari data yang dikirim form (belum disimpan). */
    public function preview(Request $request)
    {
        $validated = $this->validatePayload($request);

        $binary = $this->tte->renderToBinary($validated, $validated['unit'] ?? null);

        if ($binary === null) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal membuat preview. Pastikan ekstensi GD aktif.',
            ], 500);
        }

        return response($binary, 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'no-store',
        ]);
    }

    /** Gambar spesimen berdasarkan ID tersimpan. */
    public function image($id)
    {
        $item = TteSpesimen::find($id);

        if (!$item) {
            return response()->json([
                'success' => false,
                'message' => 'Spesimen TTE tidak ditemukan.',
            ], 404);
        }

        $path = $this->tte->generate($item, $item->unit);

        if ($path === null) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal membuat gambar spesimen TTE.',
            ], 500);
        }

        return response()->file($path, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    private function validatePayload(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'pegawai_id' => 'nullable|integer',
            'nama' => 'required|string|max:255',
            'nip' => 'nullable|string|max:30',
            'jabatan' => 'nullable|string|max:255',
            'pangkat' => 'nullable|string|max:255',
            'golongan' => 'nullable|string|max:20',
            'unit' => 'nullable|string|max:255',
            'kalimat' => 'nullable|string|max:255',
            'is_active' => 'nullable|boolean',
        ], [
            'nama.required' => 'Nama penanda tangan wajib diisi.',
        ]);
    }
}
