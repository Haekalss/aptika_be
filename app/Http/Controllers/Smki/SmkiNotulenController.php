<?php

namespace App\Http\Controllers\Smki;

use App\Http\Controllers\Controller;
use App\Models\SmkiNotulen;
use App\Models\SmkiNotulenPeserta;
use App\Models\SmkiNotulenAgenda;
use App\Models\Pegawai;
use App\Models\Auditor;
use App\Services\SmkiNotulenDocxService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

class SmkiNotulenController extends Controller
{
    protected $docxService;

    public function __construct(SmkiNotulenDocxService $docxService)
    {
        $this->docxService = $docxService;
    }
    /**
     * Agenda default berdasarkan ISO 27001:2013 Clause 9.3
     */
    public static function getDefaultAgendas(): array
    {
        return [
            [
                'nomor_agenda' => 1,
                'agenda_item' => 'Status tindakan review manajemen sebelumnya',
                'clause_iso' => '9.3 a',
            ],
            [
                'nomor_agenda' => 2,
                'agenda_item' => 'Perubahan isu internal dan eksternal',
                'clause_iso' => '9.3 b',
            ],
            [
                'nomor_agenda' => 3,
                'agenda_item' => 'Umpan Balik dari kinerja SMKI',
                'clause_iso' => '9.3 c',
            ],
            [
                'nomor_agenda' => 4,
                'agenda_item' => 'Umpan balik dari pihak yang berkepentingan',
                'clause_iso' => '9.3 d',
            ],
            [
                'nomor_agenda' => 5,
                'agenda_item' => 'Hasil Penilaian risiko',
                'clause_iso' => '9.3 e',
            ],
            [
                'nomor_agenda' => 6,
                'agenda_item' => 'Peluang untuk perbaikan berkelanjutan',
                'clause_iso' => '9.3 f',
            ],
            [
                'nomor_agenda' => 7,
                'agenda_item' => 'Internal Audit',
                'clause_iso' => '9.3 c (3)',
            ],
            [
                'nomor_agenda' => 8,
                'agenda_item' => 'Kecukupan Sumber Daya',
                'clause_iso' => '9.3',
            ],
            [
                'nomor_agenda' => 9,
                'agenda_item' => 'Perbaikan Kinerja',
                'clause_iso' => '9.3',
            ],
            [
                'nomor_agenda' => 10,
                'agenda_item' => 'Rencana Tanggap Darurat',
                'clause_iso' => '9.3',
            ],
        ];
    }

    /**
     * Data lookup pendukung (Master Pegawai, Auditor, dan Agenda Standar).
     */
    public function lookup()
    {
        $pegawai = Pegawai::orderBy('nama', 'asc')->get(['id', 'nama', 'nip', 'jabatan']);
        $auditor = Auditor::orderBy('nama_auditor', 'asc')->get(['id_auditor', 'nama_auditor', 'nip_auditor']);

        // Generate nomor notulen berikutnya otomatis
        $year = date('Y');
        $lastRecord = SmkiNotulen::whereYear('tanggal', $year)->orderBy('id', 'desc')->first();
        $nextNumber = 1;
        if ($lastRecord && preg_match('/NM-\d{4}-(\d+)/', $lastRecord->nomor_notulen, $matches)) {
            $nextNumber = intval($matches[1]) + 1;
        }
        $suggestedNomorNotulen = sprintf("NM-%s-%03d", $year, $nextNumber);

        return response()->json([
            'success' => true,
            'data' => [
                'pegawai' => $pegawai,
                'auditor' => $auditor,
                'default_agendas' => self::getDefaultAgendas(),
                'suggested_nomor_notulen' => $suggestedNomorNotulen,
            ],
        ]);
    }

    /**
     * Daftar Notulen dengan pencarian dan filter tahun.
     */
    public function index(Request $request)
    {
        $query = SmkiNotulen::with(['pesertas', 'agendas'])
            ->orderBy('tanggal', 'desc')
            ->orderBy('id', 'desc');

        if ($request->filled('search')) {
            $search = trim($request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('nomor_notulen', 'like', "%{$search}%")
                  ->orWhere('judul_rapat', 'like', "%{$search}%")
                  ->orWhere('tempat', 'like', "%{$search}%")
                  ->orWhere('notulis', 'like', "%{$search}%")
                  ->orWhere('koordinator_smki', 'like', "%{$search}%");
            });
        }

        if ($request->filled('tahun') && $request->input('tahun') !== 'ALL') {
            $query->whereYear('tanggal', $request->input('tahun'));
        }

        $perPage = $request->input('per_page', 10);
        $data = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Menyimpan data notulen baru.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'nomor_notulen' => 'required|string|unique:smki_notulens,nomor_notulen',
            'judul_rapat' => 'required|string',
            'tanggal' => 'required|date',
            'waktu_mulai' => 'required|string',
            'waktu_selesai' => 'required|string',
            'tempat' => 'required|string',
            'notulis' => 'required|string',
            'koordinator_smki' => 'required|string',
            'pesertas' => 'required|array|min:1',
            'pesertas.*.nama' => 'required|string',
            'pesertas.*.jabatan' => 'required|string',
            'agendas' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal, silakan periksa input Anda.',
                'errors' => $validator->errors(),
            ], 422);
        }

        DB::beginTransaction();
        try {
            $user = $request->user();
            $notulen = SmkiNotulen::create([
                'nomor_notulen' => $request->input('nomor_notulen'),
                'no_dokumen' => $request->input('no_dokumen', 'F10-SMKI'),
                'no_revisi' => $request->input('no_revisi', '1.1'),
                'tanggal_berlaku' => $request->input('tanggal_berlaku', '2022-01-20'),
                'judul_formulir' => $request->input('judul_formulir', 'Formulir Notulen Management Review SMKI'),
                'judul_rapat' => $request->input('judul_rapat'),
                'tanggal' => $request->input('tanggal'),
                'waktu_mulai' => $request->input('waktu_mulai'),
                'waktu_selesai' => $request->input('waktu_selesai'),
                'zona_waktu' => $request->input('zona_waktu', 'WIB'),
                'tempat' => $request->input('tempat'),
                'notulis' => $request->input('notulis'),
                'koordinator_smki' => $request->input('koordinator_smki'),
                'created_by' => $user ? $user->id : null,
                'bidang_id' => $user ? $user->bidang_id : null,
                'tujuan' => $request->input('tujuan', 'Management Review dari hasil implementasi ISO 27001 berdasarkan hasil dari kegiatan Internal Audit yang telah dilakukan dan temuan dari kegiatan Internal Audit, Memastikan sasaran SMKI terpenuhi, Meninjau Kinerja dari pihak Vendor dan pihak ketiga, Meninjau daftar Risk Register, dll'),
                'tindak_lanjut' => $request->input('tindak_lanjut', 'Hasil rapat management review ini akan diperiksa statusnya pada rapat berikutnya yang pelaksanaannya akan ditentukan kemudian.'),
            ]);

            // Simpan Peserta
            foreach ($request->input('pesertas', []) as $idx => $pesertaData) {
                SmkiNotulenPeserta::create([
                    'smki_notulen_id' => $notulen->id,
                    'pegawai_id' => $pesertaData['pegawai_id'] ?? null,
                    'nama' => $pesertaData['nama'],
                    'jabatan' => $pesertaData['jabatan'],
                    'urutan' => $idx + 1,
                ]);
            }

            // Simpan 10 Agenda
            $agendasInput = $request->input('agendas');
            if (empty($agendasInput)) {
                $agendasInput = self::getDefaultAgendas();
            }

            foreach ($agendasInput as $agenda) {
                SmkiNotulenAgenda::create([
                    'smki_notulen_id' => $notulen->id,
                    'nomor_agenda' => $agenda['nomor_agenda'],
                    'agenda_item' => $agenda['agenda_item'],
                    'clause_iso' => $agenda['clause_iso'] ?? null,
                    'outcomes' => $agenda['outcomes'] ?? null,
                    'actions_output' => $agenda['actions_output'] ?? null,
                    'pic' => $agenda['pic'] ?? null,
                    'tgl_penyelesaian' => !empty($agenda['tgl_penyelesaian']) ? $agenda['tgl_penyelesaian'] : null,
                ]);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Notulen Management Review berhasil disimpan!',
                'data' => $notulen->load(['pesertas', 'agendas']),
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Gagal menyimpan notulen: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Detail notulen berdasarkan ID.
     */
    public function show($id)
    {
        $notulen = SmkiNotulen::with(['pesertas', 'agendas'])->find($id);

        if (!$notulen) {
            return response()->json([
                'success' => false,
                'message' => 'Data Notulen tidak ditemukan.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $notulen,
        ]);
    }

    /**
     * Update data notulen.
     */
    public function update(Request $request, $id)
    {
        $notulen = SmkiNotulen::find($id);

        if (!$notulen) {
            return response()->json([
                'success' => false,
                'message' => 'Data Notulen tidak ditemukan.',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'nomor_notulen' => 'required|string|unique:smki_notulens,nomor_notulen,' . $id,
            'judul_rapat' => 'required|string',
            'tanggal' => 'required|date',
            'waktu_mulai' => 'required|string',
            'waktu_selesai' => 'required|string',
            'tempat' => 'required|string',
            'notulis' => 'required|string',
            'koordinator_smki' => 'required|string',
            'pesertas' => 'required|array|min:1',
            'agendas' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal, silakan periksa input Anda.',
                'errors' => $validator->errors(),
            ], 422);
        }

        DB::beginTransaction();
        try {
            $notulen->update([
                'nomor_notulen' => $request->input('nomor_notulen'),
                'no_dokumen' => $request->input('no_dokumen', $notulen->no_dokumen),
                'no_revisi' => $request->input('no_revisi', $notulen->no_revisi),
                'tanggal_berlaku' => $request->input('tanggal_berlaku', $notulen->tanggal_berlaku),
                'judul_formulir' => $request->input('judul_formulir', $notulen->judul_formulir),
                'judul_rapat' => $request->input('judul_rapat'),
                'tanggal' => $request->input('tanggal'),
                'waktu_mulai' => $request->input('waktu_mulai'),
                'waktu_selesai' => $request->input('waktu_selesai'),
                'zona_waktu' => $request->input('zona_waktu', $notulen->zona_waktu),
                'tempat' => $request->input('tempat'),
                'notulis' => $request->input('notulis'),
                'koordinator_smki' => $request->input('koordinator_smki'),
                'tujuan' => $request->input('tujuan', $notulen->tujuan),
                'tindak_lanjut' => $request->input('tindak_lanjut', $notulen->tindak_lanjut),
            ]);

            // Update Peserta: hapus lama, ganti baru
            SmkiNotulenPeserta::where('smki_notulen_id', $notulen->id)->delete();
            foreach ($request->input('pesertas', []) as $idx => $pesertaData) {
                SmkiNotulenPeserta::create([
                    'smki_notulen_id' => $notulen->id,
                    'pegawai_id' => $pesertaData['pegawai_id'] ?? null,
                    'nama' => $pesertaData['nama'],
                    'jabatan' => $pesertaData['jabatan'],
                    'urutan' => $idx + 1,
                ]);
            }

            // Update Agendas
            if ($request->has('agendas')) {
                SmkiNotulenAgenda::where('smki_notulen_id', $notulen->id)->delete();
                foreach ($request->input('agendas', []) as $agenda) {
                    SmkiNotulenAgenda::create([
                        'smki_notulen_id' => $notulen->id,
                        'nomor_agenda' => $agenda['nomor_agenda'],
                        'agenda_item' => $agenda['agenda_item'],
                        'clause_iso' => $agenda['clause_iso'] ?? null,
                        'outcomes' => $agenda['outcomes'] ?? null,
                        'actions_output' => $agenda['actions_output'] ?? null,
                        'pic' => $agenda['pic'] ?? null,
                        'tgl_penyelesaian' => !empty($agenda['tgl_penyelesaian']) ? $agenda['tgl_penyelesaian'] : null,
                    ]);
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Notulen berhasil diperbarui!',
                'data' => $notulen->fresh(['pesertas', 'agendas']),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Gagal memperbarui notulen: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Hapus permanen notulen beserta relasinya (peserta & agenda) dari database.
     */
    public function destroy($id)
    {
        $notulen = SmkiNotulen::withTrashed()->find($id);

        if (!$notulen) {
            return response()->json([
                'success' => false,
                'message' => 'Data Notulen tidak ditemukan.',
            ], 404);
        }

        try {
            DB::beginTransaction();

            // Hapus relasi anak terlebih dahulu
            SmkiNotulenPeserta::where('smki_notulen_id', $notulen->id)->delete();
            SmkiNotulenAgenda::where('smki_notulen_id', $notulen->id)->delete();

            // Hapus permanen dari database
            $notulen->forceDelete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Notulen berhasil dihapus permanen dari database.',
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus notulen: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Ekspor Notulen ke dokumen Word (.docx) resmi FR-011.
     */
    public function exportDocx($id)
    {
        $notulen = SmkiNotulen::with(['pesertas', 'agendas'])->find($id);
        if (!$notulen) {
            return response()->json([
                'success' => false,
                'message' => 'Data Notulen tidak ditemukan.',
            ], 404);
        }

        try {
            $docxPath = $this->docxService->generateDocx($notulen);
            $cleanNomor = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $notulen->nomor_notulen ?: 'FR-011');
            $fileName = 'FR-011_' . $cleanNomor . '.docx';

            return response()->download($docxPath, $fileName, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ])->deleteFileAfterSend(true);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengekspor dokumen Word: ' . $e->getMessage(),
            ], 500);
        }
    }
}
