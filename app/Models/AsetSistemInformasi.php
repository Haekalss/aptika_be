<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AsetSistemInformasi;
use Illuminate\Http\Request;

class AsetSistemInformasiController extends Controller
{
    // Mengambil semua data untuk ditampilkan di tabel dan kotak statistik
    public function index()
    {
        $aset = AsetSistemInformasi::latest()->get();
        
        // Menghitung statistik untuk kotak di atas tabel
        $stats = [
            'total' => $aset->count(),
            'aplikasi_web' => $aset->where('kategori_sistem', 'Sistem Informasi')->count(),
            'server_vm' => $aset->where('kategori_sistem', 'Virtualisasi')->count(),
            'sistem_rahasia' => $aset->where('klasifikasi', 'Rahasia')->count(),
        ];

        return response()->json([
            'status' => 'success',
            'stats' => $stats,
            'data' => $aset
        ]);
    }

    // Menyimpan data baru dari form Modal FR-036
    public function store(Request $request)
    {
        $validated = $request->validate([
            'nama_sistem' => 'required|string|max:255',
            'spesifikasi' => 'required|string',
            // tambahkan validasi lain jika perlu...
        ]);

        $aset = AsetSistemInformasi::create($request->all());

        return response()->json([
            'status' => 'success',
            'message' => 'Aset Sistem Informasi berhasil ditambahkan',
            'data' => $aset
        ], 201);
    }
}
