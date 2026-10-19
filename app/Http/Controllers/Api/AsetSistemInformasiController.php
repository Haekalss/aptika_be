<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AsetSistemInformasi;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AsetSistemInformasiController extends Controller
{
    /**
     * Menampilkan daftar Aset Sistem Informasi (FR-036)
     * beserta statistik ringkas untuk kartu di halaman.
     */
    public function index(Request $request)
    {
        $query = AsetSistemInformasi::query()->latest();

        if ($request->filled('search')) {
            $search = trim($request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('nama_sistem', 'like', "%{$search}%")
                    ->orWhere('kode_aset_ti', 'like', "%{$search}%")
                    ->orWhere('kode_virtualisasi', 'like', "%{$search}%")
                    ->orWhere('kode_sistem_info', 'like', "%{$search}%")
                    ->orWhere('ip_address', 'like', "%{$search}%")
                    ->orWhere('pemilik', 'like', "%{$search}%");
            });
        }

        if ($request->filled('kategori_sistem')) {
            $query->where('kategori_sistem', $request->input('kategori_sistem'));
        }

        if ($request->filled('klasifikasi')) {
            $query->where('klasifikasi', $request->input('klasifikasi'));
        }

        $aset = $query->get();

        // Statistik dihitung dari seluruh data, bukan hanya hasil filter,
        // agar kartu ringkasan tetap konsisten dengan total keseluruhan.
        $semua = AsetSistemInformasi::query();
        $stats = [
            'total' => (clone $semua)->count(),
            'aplikasi_web' => (clone $semua)->where('kategori_sistem', 'Sistem Informasi')->count(),
            'server_vm' => (clone $semua)->where('kategori_sistem', 'Virtualisasi')->count(),
            'sistem_rahasia' => (clone $semua)->where('klasifikasi', 'Rahasia')->count(),
        ];

        return response()->json([
            'status' => 'success',
            'stats' => $stats,
            'data' => $aset,
        ]);
    }

    /**
     * Menyimpan data Aset Sistem Informasi baru.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'nama_sistem' => 'required|string|max:255',
            'spesifikasi' => 'required|string',
            'kode_aset_ti' => 'nullable|string|max:255',
            'kode_virtualisasi' => 'nullable|string|max:255',
            'kode_sistem_info' => 'nullable|string|max:255',
            'ip_address' => 'nullable|string|max:255',
            'deskripsi' => 'nullable|string',
            'kategori_sistem' => 'nullable|string|max:255',
            'pemilik' => 'nullable|string|max:255',
            'klasifikasi' => 'nullable|string|max:255',
        ], [
            'nama_sistem.required' => 'Nama sistem wajib diisi.',
            'spesifikasi.required' => 'Spesifikasi sistem wajib diisi.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $aset = AsetSistemInformasi::create($validator->validated());

        return response()->json([
            'status' => 'success',
            'message' => 'Aset Sistem Informasi berhasil ditambahkan',
            'data' => $aset,
        ], 201);
    }

    /**
     * Menampilkan detail satu Aset Sistem Informasi.
     */
    public function show($id)
    {
        $aset = AsetSistemInformasi::find($id);

        if (!$aset) {
            return response()->json([
                'status' => 'error',
                'message' => 'Aset Sistem Informasi tidak ditemukan.',
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $aset,
        ]);
    }

    /**
     * Memperbarui data Aset Sistem Informasi.
     */
    public function update(Request $request, $id)
    {
        $aset = AsetSistemInformasi::find($id);

        if (!$aset) {
            return response()->json([
                'status' => 'error',
                'message' => 'Aset Sistem Informasi tidak ditemukan.',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'nama_sistem' => 'sometimes|required|string|max:255',
            'spesifikasi' => 'sometimes|required|string',
            'kode_aset_ti' => 'nullable|string|max:255',
            'kode_virtualisasi' => 'nullable|string|max:255',
            'kode_sistem_info' => 'nullable|string|max:255',
            'ip_address' => 'nullable|string|max:255',
            'deskripsi' => 'nullable|string',
            'kategori_sistem' => 'nullable|string|max:255',
            'pemilik' => 'nullable|string|max:255',
            'klasifikasi' => 'nullable|string|max:255',
        ], [
            'nama_sistem.required' => 'Nama sistem wajib diisi.',
            'spesifikasi.required' => 'Spesifikasi sistem wajib diisi.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $aset->update($validator->validated());

        return response()->json([
            'status' => 'success',
            'message' => 'Aset Sistem Informasi berhasil diperbarui',
            'data' => $aset->fresh(),
        ]);
    }

    /**
     * Menghapus data Aset Sistem Informasi.
     */
    public function destroy($id)
    {
        $aset = AsetSistemInformasi::find($id);

        if (!$aset) {
            return response()->json([
                'status' => 'error',
                'message' => 'Aset Sistem Informasi tidak ditemukan.',
            ], 404);
        }

        $aset->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Aset Sistem Informasi berhasil dihapus',
        ]);
    }
}
