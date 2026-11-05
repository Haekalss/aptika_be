<?php

namespace App\Http\Controllers\Smki;

use App\Http\Controllers\Controller;
use App\Models\HakAksesTi;
use App\Models\MasterJenisAkses;
use App\Models\MasterJenisPermohonan;
use App\Models\MasterLevelAkses;
use App\Models\MasterSistemAplikasi;
use App\Models\MasterUnitKerja;
use App\Services\HakAksesTiDocxService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class HakAksesTiController extends Controller
{
    protected HakAksesTiDocxService $docxService;

    public function __construct(HakAksesTiDocxService $docxService)
    {
        $this->docxService = $docxService;
    }

    /**
     * Daftar permohonan Hak Akses TI beserta statistik KPI.
     */
    public function index(Request $request)
    {
        $query = HakAksesTi::with([
            'unitKerja',
            'jenisPermohonan',
            'sistemAplikasi',
            'levelAkses',
            'jenisAkses',
        ])->orderBy('id_hak_akses', 'desc');

        if ($request->filled('search')) {
            $search = trim($request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('nomor_request', 'like', "%{$search}%")
                  ->orWhere('nama_pemohon', 'like', "%{$search}%")
                  ->orWhere('nip_id_pegawai', 'like', "%{$search}%")
                  ->orWhere('jabatan', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhereHas('unitKerja', fn($u) => $u->where('nama_unit', 'like', "%{$search}%"))
                  ->orWhereHas('sistemAplikasi', fn($s) => $s->where('nama_sistem', 'like', "%{$search}%"));
            });
        }

        if ($request->filled('jenis_permohonan')) {
            $query->where('id_jenis_permohonan', $request->input('jenis_permohonan'));
        }

        if ($request->filled('status')) {
            $query->where('status_permohonan', $request->input('status'));
        }

        if ($request->filled('sifat_akses')) {
            $query->where('sifat_akses', $request->input('sifat_akses'));
        }

        // Statistik global
        $base = HakAksesTi::query();
        $stats = [
            'total_permohonan'   => (clone $base)->count(),
            'permohonan_disetujui' => (clone $base)->where('status_permohonan', 'Disetujui')->count(),
            'akses_permanen'     => (clone $base)->where('sifat_akses', 'Permanen')->count(),
            'akses_sementara'    => (clone $base)->where('sifat_akses', 'Sementara')->count(),
            'menunggu'           => (clone $base)->where('status_permohonan', 'Menunggu')->count(),
        ];

        $perPage = (int) $request->input('per_page', 10);
        if ($perPage === -1) {
            $items = $query->get();
            $paginated = null;
        } else {
            $paginated = $query->paginate($perPage);
            $items = $paginated->items();
        }

        return response()->json([
            'success' => true,
            'data'    => $items,
            'stats'   => $stats,
            'meta' => $paginated ? [
                'current_page' => $paginated->currentPage(),
                'last_page'    => $paginated->lastPage(),
                'per_page'     => $paginated->perPage(),
                'total'        => $paginated->total(),
            ] : [
                'current_page' => 1,
                'last_page'    => 1,
                'per_page'     => count($items),
                'total'        => count($items),
            ],
        ]);
    }

    /**
     * Master lookup (unit kerja, jenis permohonan, sistem, level, jenis akses) + nomor request berikutnya.
     */
    public function lookup()
    {
        $latest = HakAksesTi::orderBy('id_hak_akses', 'desc')->first();
        $nextId = $latest ? ($latest->id_hak_akses + 1) : 1;
        $suggestedNomor = sprintf('HAK-AKSES/%s/%04d', date('Y'), $nextId);

        return response()->json([
            'success' => true,
            'data'    => [
                'unit_kerjas'       => MasterUnitKerja::orderBy('nama_unit')->get(),
                'jenis_permohonans' => MasterJenisPermohonan::orderBy('id_jenis_permohonan')->get(),
                'sistem_aplikasis'  => MasterSistemAplikasi::orderBy('nama_sistem')->get(),
                'level_akses'       => MasterLevelAkses::orderBy('id_level_akses')->get(),
                'jenis_akses'       => MasterJenisAkses::orderBy('id_jenis_akses')->get(),
                'suggested_nomor'   => $suggestedNomor,
                'sifat_akses'       => ['Permanen', 'Rutin', 'Sementara'],
                'waktu_akses'       => ['Jam Kerja', '24 Jam', 'Lainnya'],
                'status_permohonan' => ['Menunggu', 'Diproses', 'Disetujui', 'Ditolak'],
            ],
        ]);
    }

    public function show($id)
    {
        $item = HakAksesTi::with([
            'unitKerja',
            'jenisPermohonan',
            'sistemAplikasi',
            'levelAkses',
            'jenisAkses',
        ])->find($id);

        if (!$item) {
            return response()->json([
                'success' => false,
                'message' => 'Data Hak Akses TI tidak ditemukan.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data'    => $item,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'nama_pemohon'          => 'required|string|max:255',
            'nip_id_pegawai'        => 'nullable|string|max:100',
            'jabatan'               => 'nullable|string|max:255',
            'email'                 => 'nullable|string|max:255',
            'kontak_person'         => 'nullable|string|max:100',
            'id_unit_kerja'         => 'nullable|integer',
            'id_jenis_permohonan'   => 'nullable|integer',
            'id_sistem_aplikasi'    => 'nullable|integer',
            'id_level_akses'        => 'nullable|integer',
            'sifat_akses'           => 'nullable|string',
            'waktu_akses'           => 'nullable|string',
            'waktu_akses_lainnya'   => 'nullable|string',
            'masa_berlaku_mulai'    => 'nullable|date',
            'masa_berlaku_selesai'  => 'nullable|date',
            'keperluan'             => 'nullable|string',
            'sistem_lainnya'        => 'nullable|string',
            'modul_fitur'           => 'nullable|array',
            'persetujuan_ketentuan' => 'nullable|boolean',
            'status_permohonan'     => 'nullable|string',
            'jenis_akses'           => 'nullable|array',
            'atasan_nama'           => 'nullable|string|max:255',
            'atasan_nip'            => 'nullable|string|max:50',
            'penanggung_jawab_nama' => 'nullable|string|max:255',
            'penanggung_jawab_nip'  => 'nullable|string|max:50',
            'agen_nama'             => 'nullable|string|max:255',
            'agen_nip'              => 'nullable|string|max:50',
        ]);

        $jenisAksesIds = $validated['jenis_akses'] ?? [];
        unset($validated['jenis_akses']);

        $validated['nomor_request'] = $request->input('nomor_request') ?: HakAksesTi::generateNomorRequest();
        $validated['status_permohonan'] = $validated['status_permohonan'] ?? 'Menunggu';
        $validated['persetujuan_ketentuan'] = (bool) ($validated['persetujuan_ketentuan'] ?? false);
        $validated['bidang_id'] = $request->user()?->bidang_id;
        $validated['user_id'] = $request->user()?->id;

        $item = DB::transaction(function () use ($validated, $jenisAksesIds) {
            $item = HakAksesTi::create($validated);
            if (!empty($jenisAksesIds)) {
                $item->jenisAkses()->sync($jenisAksesIds);
            }
            return $item;
        });

        return response()->json([
            'success' => true,
            'message' => 'Formulir Hak Akses TI berhasil disimpan.',
            'data'    => $item->load(['unitKerja', 'jenisPermohonan', 'sistemAplikasi', 'levelAkses', 'jenisAkses']),
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $item = HakAksesTi::find($id);
        if (!$item) {
            return response()->json([
                'success' => false,
                'message' => 'Data Hak Akses TI tidak ditemukan.',
            ], 404);
        }

        $validated = $request->validate([
            'nama_pemohon'          => 'sometimes|required|string|max:255',
            'nip_id_pegawai'        => 'nullable|string|max:100',
            'jabatan'               => 'nullable|string|max:255',
            'email'                 => 'nullable|string|max:255',
            'kontak_person'         => 'nullable|string|max:100',
            'id_unit_kerja'         => 'nullable|integer',
            'id_jenis_permohonan'   => 'nullable|integer',
            'id_sistem_aplikasi'    => 'nullable|integer',
            'id_level_akses'        => 'nullable|integer',
            'sifat_akses'           => 'nullable|string',
            'waktu_akses'           => 'nullable|string',
            'waktu_akses_lainnya'   => 'nullable|string',
            'masa_berlaku_mulai'    => 'nullable|date',
            'masa_berlaku_selesai'  => 'nullable|date',
            'keperluan'             => 'nullable|string',
            'sistem_lainnya'        => 'nullable|string',
            'modul_fitur'           => 'nullable|array',
            'persetujuan_ketentuan' => 'nullable|boolean',
            'status_permohonan'     => 'nullable|string',
            'jenis_akses'           => 'nullable|array',
            'atasan_nama'           => 'nullable|string|max:255',
            'atasan_nip'            => 'nullable|string|max:50',
            'penanggung_jawab_nama' => 'nullable|string|max:255',
            'penanggung_jawab_nip'  => 'nullable|string|max:50',
            'agen_nama'             => 'nullable|string|max:255',
            'agen_nip'              => 'nullable|string|max:50',
        ]);

        $hasJenis = array_key_exists('jenis_akses', $validated);
        $jenisAksesIds = $validated['jenis_akses'] ?? [];
        unset($validated['jenis_akses']);

        DB::transaction(function () use ($item, $validated, $hasJenis, $jenisAksesIds) {
            $item->update($validated);
            if ($hasJenis) {
                $item->jenisAkses()->sync($jenisAksesIds);
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Data Hak Akses TI berhasil diperbarui.',
            'data'    => $item->fresh()->load(['unitKerja', 'jenisPermohonan', 'sistemAplikasi', 'levelAkses', 'jenisAkses']),
        ]);
    }

    public function destroy($id)
    {
        $item = HakAksesTi::find($id);
        if (!$item) {
            return response()->json([
                'success' => false,
                'message' => 'Data Hak Akses TI tidak ditemukan.',
            ], 404);
        }

        $nomor = $item->nomor_request;
        $item->jenisAkses()->detach();
        $item->delete();

        return response()->json([
            'success' => true,
            'message' => "Formulir Hak Akses TI {$nomor} berhasil dihapus.",
        ]);
    }

    /**
     * Ekspor formulir ke dokumen Word (.docx). Bisa satu record (id) atau banyak (filter).
     */
    public function exportDocx(Request $request)
    {
        $query = HakAksesTi::with(['unitKerja', 'jenisPermohonan', 'sistemAplikasi', 'levelAkses', 'jenisAkses'])
            ->orderBy('id_hak_akses', 'desc');

        if ($request->filled('id')) {
            $query->where('id_hak_akses', $request->input('id'));
        } else {
            if ($request->filled('search')) {
                $search = trim($request->input('search'));
                $query->where(function ($q) use ($search) {
                    $q->where('nomor_request', 'like', "%{$search}%")
                      ->orWhere('nama_pemohon', 'like', "%{$search}%")
                      ->orWhere('jabatan', 'like', "%{$search}%");
                });
            }
            if ($request->filled('status')) {
                $query->where('status_permohonan', $request->input('status'));
            }
            if ($request->filled('sifat_akses')) {
                $query->where('sifat_akses', $request->input('sifat_akses'));
            }
        }

        $items = $query->get();

        $options = [
            'no_dokumen'     => $request->input('no_dokumen', 'FR-018/KOM.03.05/ SANDIKAMI'),
            'no_revisi'      => $request->input('no_revisi', '1.1'),
            'tanggal_berlaku' => $request->input('tanggal_berlaku', '07 Juli 2022'),
        ];

        try {
            $filePath = $this->docxService->generateDocx($items, $options);
            $filename = 'FR-018_Formulir_Hak_Akses_TI_' . date('Ymd_His') . '.docx';

            return response()->download($filePath, $filename, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ])->deleteFileAfterSend(true);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal menghasilkan dokumen FR-018: ' . $e->getMessage(),
            ], 500);
        }
    }
}
