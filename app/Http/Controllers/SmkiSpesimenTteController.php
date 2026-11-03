<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\SmkiSpesimenTte;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Spesimen TTE (gambar tanda tangan) per pengguna.
 * Hanya pemilik yang boleh mengunggah/menghapus; semua pengguna SMKI boleh melihat
 * (karena spesimen tercetak pada formulir).
 */
class SmkiSpesimenTteController extends Controller
{
    private const DISK = 'local';

    /** Status spesimen milik pengguna yang login. */
    public function saya(Request $request)
    {
        $s = SmkiSpesimenTte::where('user_id', $request->user()->id)->first();

        return response()->json([
            'success' => true,
            'data'    => [
                'ada'       => (bool) $s,
                'nama_asli' => $s?->nama_asli,
                'ukuran'    => $s ? (int) $s->ukuran : null,
            ],
        ]);
    }

    public function simpan(Request $request)
    {
        $request->validate([
            'spesimen' => ['required', 'file', 'mimes:png,jpg,jpeg', 'max:1024'],
        ], [
            'required' => 'Berkas spesimen wajib dipilih.',
            'mimes'    => 'Spesimen harus berformat PNG atau JPG.',
            'max'      => 'Spesimen maksimal 1 MB.',
            'file'     => 'Spesimen harus berupa berkas.',
        ]);

        $user = $request->user();
        $file = $request->file('spesimen');
        $lama = SmkiSpesimenTte::where('user_id', $user->id)->first();

        $path = $file->store("smki/spesimen-tte/{$user->id}", self::DISK);

        SmkiSpesimenTte::updateOrCreate(
            ['user_id' => $user->id],
            [
                'nama_asli' => $file->getClientOriginalName(),
                'path'      => $path,
                'mime'      => $file->getClientMimeType(),
                'ukuran'    => $file->getSize(),
            ]
        );

        if ($lama && $lama->path !== $path) {
            Storage::disk(self::DISK)->delete($lama->path);
        }

        return response()->json(['success' => true, 'message' => 'Spesimen TTE berhasil disimpan!']);
    }

    public function hapus(Request $request)
    {
        $s = SmkiSpesimenTte::where('user_id', $request->user()->id)->firstOrFail();

        Storage::disk(self::DISK)->delete($s->path);
        $s->delete();

        return response()->json(['success' => true, 'message' => 'Spesimen TTE dihapus.']);
    }

    /** Tampilkan gambar spesimen milik user tertentu (untuk preview & ekspor). */
    public function tampil(Request $request, $userId)
    {
        $s = SmkiSpesimenTte::where('user_id', $userId)->first();

        if (!$s || !Storage::disk(self::DISK)->exists($s->path)) {
            abort(404, 'Spesimen TTE tidak ditemukan.');
        }

        return Storage::disk(self::DISK)->response($s->path, $s->nama_asli, [
            'Content-Type'  => $s->mime ?: 'image/png',
            'Cache-Control' => 'private, no-cache',
        ]);
    }
}