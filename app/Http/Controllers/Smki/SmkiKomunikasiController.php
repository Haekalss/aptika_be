<?php

namespace App\Http\Controllers\Smki;

use App\Http\Controllers\Controller;
use App\Models\SmkiKomunikasi;
use App\Models\SmkiKomunikasiHeader;
use Illuminate\Http\Request;

class SmkiKomunikasiController extends Controller
{
    private const DEFAULT_HEADER = [
        'no_dokumen' => 'FR-010/KOM.03.05/SANDIKAMI',
        'no_revisi' => '1.1',
        'tanggal_berlaku' => '07 Juli 2022',
    ];

    public function index(Request $request)
    {
        $periode = $request->input('periode', date('Y'));
        $query = SmkiKomunikasi::with('user:id,name,email')
            ->where('periode', $periode)
            ->orderByDesc('id');

        if ($request->filled('search')) {
            $search = trim($request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('judul', 'like', "%{$search}%")
                    ->orWhere('pengirim', 'like', "%{$search}%")
                    ->orWhere('penerima', 'like', "%{$search}%")
                    ->orWhere('metode', 'like', "%{$search}%");
            });
        }

        if ($request->filled('klasifikasi') && $request->input('klasifikasi') !== 'Semua klasifikasi') {
            $query->where('klasifikasi', $request->input('klasifikasi'));
        }

        if ($request->filled('tipe')) {
            $query->where('tipe', $request->input('tipe'));
        }

        $items = $query->get();
        $header = SmkiKomunikasiHeader::firstOrCreate(
            ['periode' => $periode],
            self::DEFAULT_HEADER
        );

        return response()->json([
            'success' => true,
            'header' => [
                'no_dokumen' => $header->no_dokumen,
                'no_revisi' => $header->no_revisi,
                'tanggal_berlaku' => $header->tanggal_berlaku,
                'periode' => $periode,
            ],
            'data' => $items,
            'internal' => $items->where('tipe', 'internal')->values(),
            'eksternal' => $items->where('tipe', 'eksternal')->values(),
            'stats' => [
                'total' => $items->count(),
                'internalCount' => $items->where('tipe', 'internal')->count(),
                'eksternalCount' => $items->where('tipe', 'eksternal')->count(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validateData($request);
        $data['user_id'] = $request->user()->id;
        $item = SmkiKomunikasi::create($data);

        return response()->json([
            'success' => true,
            'message' => 'Data komunikasi berhasil disimpan.',
            'data' => $item->load('user:id,name,email'),
        ], 201);
    }

    public function show(int $id)
    {
        return response()->json([
            'success' => true,
            'data' => SmkiKomunikasi::with('user:id,name,email')->findOrFail($id),
        ]);
    }

    public function update(Request $request, int $id)
    {
        $item = SmkiKomunikasi::findOrFail($id);
        $item->update($this->validateData($request));

        return response()->json([
            'success' => true,
            'message' => 'Data komunikasi berhasil diperbarui.',
            'data' => $item->fresh()->load('user:id,name,email'),
        ]);
    }

    public function destroy(int $id)
    {
        SmkiKomunikasi::findOrFail($id)->delete();

        return response()->json(['success' => true, 'message' => 'Data komunikasi berhasil dihapus.']);
    }

    public function updateHeader(Request $request)
    {
        $data = $request->validate([
            'periode' => 'required|digits:4',
            'no_dokumen' => 'required|string|max:100',
            'no_revisi' => 'required|string|max:30',
            'tanggal_berlaku' => 'required|string|max:100',
        ]);
        $header = SmkiKomunikasiHeader::updateOrCreate(
            ['periode' => $data['periode']],
            $data
        );

        return response()->json(['success' => true, 'data' => $header]);
    }

    private function validateData(Request $request): array
    {
        return $request->validate([
            'tipe' => 'required|in:internal,eksternal',
            'judul' => 'required|string|max:255',
            'klasifikasi' => 'required|string|in:Internal,Terbatas,Publik,Rahasia',
            'pengirim' => 'required|string|max:255',
            'penerima' => 'required|string|max:255',
            'frekuensi' => 'required|string|max:100',
            'metode' => 'required|string|max:255',
            'periode' => 'required|digits:4',
        ]);
    }
}
