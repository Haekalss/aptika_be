<?php

namespace App\Http\Controllers\Smki;

use App\Http\Controllers\Controller;
use App\Models\MasterUnitKerja;
use App\Models\SmkiPerubahanIt;
use App\Models\User;
use App\Support\SmkiPerubahanItAkses as Akses;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Formulir Permintaan Perubahan TI / RFC (FR-015).
 */
class SmkiPerubahanItController extends Controller
{
    private const DISK = 'local';

    /**
     * UC-01 & UC-02: daftar perubahan TI dengan ringkasan status, pencarian, filter, dan pagination.
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $query = $this->queryTerlihat($user)
            ->with(['lampirans', 'riwayats', 'unitKerja'])
            ->orderByDesc('id');

        if ($request->filled('search')) {
            $search = trim($request->input('search'));
            $nameCol = SmkiPerubahanIt::UNIT_NAME_COLUMN;

            $query->where(function ($q) use ($search, $nameCol) {
                $q->where('no_rfc', 'like', "%{$search}%")
                    ->orWhere('judul', 'like', "%{$search}%")
                    ->orWhere('pemohon', 'like', "%{$search}%")
                    ->orWhere('perangkat_daerah', 'like', "%{$search}%")
                    ->orWhere('nomor_surat_kontak', 'like', "%{$search}%")
                    ->orWhereHas('unitKerja', fn ($u) => $u->where($nameCol, 'like', "%{$search}%"));

                // Pencarian nomor, mis. "FR-015/003" atau "3"
                if (preg_match('/^(?:fr-?015\/?)?0*(\d+)$/i', $search, $m)) {
                    $q->orWhere('no_rfc', 'like', '%/' . str_pad($m[1], 3, '0', STR_PAD_LEFT));
                }
            });
        }

        if ($request->filled('unit_kerja_id')) {
            $query->where('unit_kerja_id', $request->input('unit_kerja_id'));
        }

        if ($request->filled('status') && array_key_exists($request->input('status'), SmkiPerubahanIt::STATUS_LABEL)) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('jenis_perubahan') && array_key_exists($request->input('jenis_perubahan'), SmkiPerubahanIt::JENIS_PERUBAHAN)) {
            $query->where('jenis_perubahan', $request->input('jenis_perubahan'));
        }

        if ($request->filled('tingkat_risiko') && array_key_exists($request->input('tingkat_risiko'), SmkiPerubahanIt::TINGKAT_RISIKO)) {
            $query->where('tingkat_risiko', $request->input('tingkat_risiko'));
        }

        $perPage = (int) $request->input('per_page', 10);

        if ($perPage === -1) {
            $items = $query->get();
            $meta = [
                'current_page' => 1,
                'last_page'    => 1,
                'per_page'     => $items->count(),
                'total'        => $items->count(),
            ];
        } else {
            $paginated = $query->paginate(max(1, $perPage));
            $items = collect($paginated->items());
            $meta = [
                'current_page' => $paginated->currentPage(),
                'last_page'    => $paginated->lastPage(),
                'per_page'     => $paginated->perPage(),
                'total'        => $paginated->total(),
            ];
        }

        return response()->json([
            'success' => true,
            'data'    => $items->map(fn ($i) => $this->transform($i, $user))->values(),
            'stats'   => $this->hitungStatistik($user),
            'meta'    => $meta,
        ]);
    }

    /**
     * UC-03: detail perubahan TI beserta riwayat tahapan.
     */
    public function show(Request $request, $id)
    {
        $user = $request->user();
        $item = SmkiPerubahanIt::with(['lampirans', 'riwayats', 'unitKerja'])->findOrFail($id);
        $this->pastikanTerlihat($item, $user);

        return response()->json([
            'success' => true,
            'data'    => $this->transform($item, $user),
        ]);
    }

    /**
     * UC-05: simpan sebagai Draft atau langsung ajukan.
     * Field "aksi_simpan": "draft" (default) atau "ajukan".
     */
    public function store(Request $request)
    {
        $user = $request->user();
        $submit = $this->isSubmit($request);
        $validated = $this->validatePayload($request, $submit);
        $files = Arr::wrap($request->file('lampiran', []));

        $stored = [];

        try {
            $item = DB::transaction(function () use ($validated, $files, $user, $submit, &$stored) {
                $item = $this->buatDenganRfc(array_merge(
                    Arr::only($validated, SmkiPerubahanIt::FORM_FIELDS),
                    $this->dataPengesah($validated),
                    [
                        'status'     => SmkiPerubahanIt::STATUS_DRAFT,
                        'pemohon'    => $user?->name,
                        'created_by' => $user?->id,
                    ]
                ));

                $this->simpanLampiran($item, $files, $stored);

                if ($submit) {
                    $this->terapkanPengajuan($item, $user);
                } else {
                    $this->catat($item, 'draft_dibuat', null, SmkiPerubahanIt::STATUS_DRAFT, null, $user);
                }

                return $item;
            });
        } catch (Throwable $e) {
            $this->hapusBerkas($stored);
            throw $e;
        }

        return response()->json([
            'success' => true,
            'message' => $submit
                ? 'Perubahan TI berhasil diajukan!'
                : 'Draft perubahan TI berhasil disimpan!',
            'data'    => $this->transform($item->fresh(['lampirans', 'riwayats', 'unitKerja']), $user),
        ], 201);
    }

    /**
     * UC-06: ubah formulir berstatus Draft / Dikembalikan.
     * Lampiran baru dikirim lewat "lampiran[]", lampiran lama dihapus lewat "hapus_lampiran[]".
     * (Dikirim sebagai POST + _method=PUT karena memakai multipart/form-data.)
     */
    public function update(Request $request, $id)
    {
        $user = $request->user();
        $item = SmkiPerubahanIt::with('lampirans')->findOrFail($id);

        $this->pastikan(
            Akses::izin($item, $user)['can_edit'],
            'Formulir ini tidak dapat diubah. Hanya formulir berstatus Draft atau Dikembalikan milik Anda yang bisa diubah.'
        );

        $submit = $this->isSubmit($request);
        $validated = $this->validatePayload($request, $submit);
        $files = Arr::wrap($request->file('lampiran', []));

        $hapusIds = array_map('intval', $validated['hapus_lampiran'] ?? []);
        $lampiranHapus = $item->lampirans->whereIn('id', $hapusIds);

        $maks = (int) config('smki_perubahan_it.lampiran_maks_berkas', 5);
        $sisa = $item->lampirans->count() - $lampiranHapus->count() + count($files);
        if ($sisa > $maks) {
            throw ValidationException::withMessages([
                'lampiran' => ["Lampiran maksimal {$maks} berkas."],
            ]);
        }

        $stored = [];
        $hapusPath = [];

        try {
            DB::transaction(function () use ($item, $validated, $files, $lampiranHapus, $user, $submit, &$stored, &$hapusPath) {
                $item->update(array_merge(
                    Arr::only($validated, SmkiPerubahanIt::FORM_FIELDS),
                    $this->dataPengesah($validated)
                ));

                foreach ($lampiranHapus as $lampiran) {
                    $hapusPath[] = $lampiran->path;
                    $lampiran->delete();
                }

                $this->simpanLampiran($item, $files, $stored);

                if ($submit) {
                    $this->terapkanPengajuan($item, $user);
                }
            });
        } catch (Throwable $e) {
            $this->hapusBerkas($stored);
            throw $e;
        }

        // Berkas lama baru dihapus dari disk setelah transaksi berhasil
        $this->hapusBerkas($hapusPath);

        return response()->json([
            'success' => true,
            'message' => $submit
                ? 'Perubahan TI berhasil diajukan!'
                : 'Perubahan TI berhasil diperbarui!',
            'data'    => $this->transform($item->fresh(['lampirans', 'riwayats', 'unitKerja']), $user),
        ]);
    }

    /**
     * UC-07: hapus formulir berstatus Draft.
     * Lampiran dan riwayat ikut terhapus lewat cascadeOnDelete di migration.
     */
    public function destroy(Request $request, $id)
    {
        $user = $request->user();
        $item = SmkiPerubahanIt::findOrFail($id);

        $this->pastikan(
            Akses::izin($item, $user)['can_delete'],
            'Hanya formulir berstatus Draft milik Anda yang dapat dihapus.'
        );

        $folder = "smki/perubahan-it/{$item->id}";

        DB::transaction(fn () => $item->delete());

        Storage::disk(self::DISK)->deleteDirectory($folder);

        return response()->json([
            'success' => true,
            'message' => 'Data perubahan TI berhasil dihapus!',
        ]);
    }

    /**
     * UC-08, UC-09, UC-10 (+ pengajuan dari detail): aksi alur kerja.
     *
     * aksi: ajukan | terima | kembalikan | setujui | tolak | laksanakan | simpan_catatan
     */
    public function aksi(Request $request, $id)
    {
        $user = $request->user();

        $data = $request->validate([
            'aksi'                => ['required', Rule::in(['ajukan', 'terima', 'kembalikan', 'setujui', 'tolak', 'laksanakan', 'simpan_catatan'])],
            'catatan'             => ['nullable', 'string', 'max:5000'],
            'tanggal_pelaksanaan' => ['nullable', 'date', 'before_or_equal:today'],
        ], $this->pesanValidasi(), $this->namaAtribut());

        $aksi = $data['aksi'];
        $catatan = isset($data['catatan']) ? trim($data['catatan']) : '';
        $catatan = $catatan === '' ? null : $catatan;

        $izinDibutuhkan = [
            'ajukan'         => 'can_ajukan',
            'terima'         => 'can_terima',
            'kembalikan'     => 'can_kembalikan',
            'setujui'        => 'can_setujui',
            'tolak'          => 'can_tolak',
            'laksanakan'     => 'can_laksanakan',
            'simpan_catatan' => 'can_laksanakan',
        ][$aksi];

        $pesan = '';

        $item = DB::transaction(function () use ($id, $user, $aksi, $catatan, $data, $izinDibutuhkan, &$pesan) {
            $item = SmkiPerubahanIt::lockForUpdate()->findOrFail($id);

            $this->pastikan(
                Akses::izin($item, $user)[$izinDibutuhkan],
                'Aksi ini tidak dapat dilakukan pada status saat ini, atau Anda tidak memiliki akses untuk melakukannya.'
            );

            $dari = $item->status;
            $sekarang = now();

            switch ($aksi) {
                case 'ajukan':
                    Validator::make(
                        $item->only(SmkiPerubahanIt::FORM_FIELDS),
                        $this->aturanFormulir(true),
                        $this->pesanValidasi(),
                        $this->namaAtribut()
                    )->validate();

                    $this->terapkanPengajuan($item, $user);
                    $pesan = 'Perubahan TI berhasil diajukan!';
                    break;

                case 'terima':
                    $item->update([
                        'status'             => SmkiPerubahanIt::STATUS_DITERIMA,
                        'diterima_oleh_id'   => $user->id,
                        'diterima_oleh_nama' => $user->name,
                        'diterima_pada'      => $sekarang,
                    ]);
                    $this->catat($item, 'terima', $dari, SmkiPerubahanIt::STATUS_DITERIMA, $catatan, $user);
                    $pesan = 'Perubahan TI diterima dan menunggu persetujuan.';
                    break;

                case 'kembalikan':
                    $this->wajibIsi($catatan, 'catatan', 'Catatan pengembalian wajib diisi.');
                    $item->update([
                        'status'               => SmkiPerubahanIt::STATUS_DIKEMBALIKAN,
                        'catatan_pengembalian' => $catatan,
                    ]);
                    $this->catat($item, 'kembalikan', $dari, SmkiPerubahanIt::STATUS_DIKEMBALIKAN, $catatan, $user);
                    $pesan = 'Perubahan TI dikembalikan kepada Pemohon.';
                    break;

                case 'setujui':
                    $item->update([
                        'status'              => SmkiPerubahanIt::STATUS_DISETUJUI,
                        'disetujui_oleh_id'   => $user->id,
                        'disetujui_oleh_nama' => $user->name,
                        'disetujui_pada'      => $sekarang,
                        'alasan_penolakan'    => null,
                    ]);
                    $this->catat($item, 'setujui', $dari, SmkiPerubahanIt::STATUS_DISETUJUI, $catatan, $user);
                    $pesan = 'Perubahan TI disetujui dan menunggu pelaksanaan.';
                    break;

                case 'tolak':
                    $this->wajibIsi($catatan, 'catatan', 'Alasan penolakan wajib diisi.');
                    $item->update([
                        'status'           => SmkiPerubahanIt::STATUS_DITOLAK,
                        'alasan_penolakan' => $catatan,
                    ]);
                    $this->catat($item, 'tolak', $dari, SmkiPerubahanIt::STATUS_DITOLAK, $catatan, $user);
                    $pesan = 'Perubahan TI ditolak.';
                    break;

                case 'laksanakan':
                    $this->wajibIsi($data['tanggal_pelaksanaan'] ?? null, 'tanggal_pelaksanaan', 'Tanggal pelaksanaan wajib diisi.');
                    $this->wajibIsi($catatan, 'catatan', 'Catatan pelaksanaan wajib diisi.');
                    $item->update([
                        'status'                 => SmkiPerubahanIt::STATUS_DILAKSANAKAN,
                        'dilaksanakan_oleh_id'   => $user->id,
                        'dilaksanakan_oleh_nama' => $user->name,
                        'dilaksanakan_pada'      => $sekarang,
                        'tanggal_pelaksanaan'    => $data['tanggal_pelaksanaan'],
                        'catatan_pelaksanaan'    => $catatan,
                    ]);
                    $this->catat($item, 'laksanakan', $dari, SmkiPerubahanIt::STATUS_DILAKSANAKAN, $catatan, $user);
                    $pesan = 'Perubahan TI ditandai telah dilaksanakan.';
                    break;

                case 'simpan_catatan':
                    $this->wajibIsi($catatan, 'catatan', 'Catatan pelaksanaan wajib diisi.');
                    $item->update(['catatan_pelaksanaan' => $catatan]);
                    $this->catat($item, 'catatan_pelaksanaan', $dari, $dari, $catatan, $user);
                    $pesan = 'Catatan pelaksanaan disimpan. Status tetap Disetujui.';
                    break;
            }

            return $item;
        });

        return response()->json([
            'success' => true,
            'message' => $pesan,
            'data'    => $this->transform($item->fresh(['lampirans', 'riwayats', 'unitKerja']), $user),
        ]);
    }

    /**
     * Opsi dropdown form & filter.
     * - Default: hanya unit kerja yang dipakai pada data Perubahan TI.
     * - ?scope=all: semua master unit kerja + daftar pengguna (untuk form tambah/ubah dan filter halaman list).
     */
    public function lookup(Request $request)
    {
        $nameCol = SmkiPerubahanIt::UNIT_NAME_COLUMN;

        $unitQuery = MasterUnitKerja::query();

        if ($request->input('scope') !== 'all') {
            $unitQuery->whereIn('id_unit_kerja', SmkiPerubahanIt::query()->whereNotNull('unit_kerja_id')->select('unit_kerja_id'));
        }

        $data = [
            'unit_kerja'       => $unitQuery->orderBy($nameCol)->get(['id_unit_kerja as id', "{$nameCol} as nama"]),
            'perangkat_daerah' => array_values((array) config('smki_perubahan_it.perangkat_daerah', [])),
        ];

        // Dropdown nama pengesah (Koordinator Agen, Penanggung Jawab, Agen) diambil dari tabel users
        if ($request->input('scope') === 'all') {
            $data['pengguna'] = User::query()->orderBy('name')->get(['id', 'name as nama']);
        }

        return response()->json([
            'success' => true,
            'data'    => $data,
        ]);
    }

    /**
     * Unduh satu lampiran (butuh login & akses SMKI lewat middleware route).
     */
    public function unduhLampiran(Request $request, $id, $lampiranId)
    {
        $item = SmkiPerubahanIt::findOrFail($id);
        $this->pastikanTerlihat($item, $request->user());

        $lampiran = $item->lampirans()->findOrFail($lampiranId);

        if (!Storage::disk(self::DISK)->exists($lampiran->path)) {
            abort(404, 'Berkas lampiran tidak ditemukan di penyimpanan.');
        }

        return Storage::disk(self::DISK)->download($lampiran->path, $lampiran->nama_asli);
    }

    // ------------------------------------------------------------------
    // Helper: akses & query
    // ------------------------------------------------------------------

    /**
     * Draft hanya terlihat oleh pemiliknya (dan admin); status lain terlihat semua pengguna SMKI.
     */
    private function queryTerlihat($user)
    {
        $query = SmkiPerubahanIt::query();

        if (!Akses::terapkan() || Akses::isAdmin($user)) {
            return $query;
        }

        return $query->where(function ($q) use ($user) {
            $q->where('status', '!=', SmkiPerubahanIt::STATUS_DRAFT)
                ->orWhere('created_by', $user?->id);
        });
    }

    private function pastikanTerlihat(SmkiPerubahanIt $item, $user): void
    {
        if (Akses::terapkan() && $item->status === SmkiPerubahanIt::STATUS_DRAFT && !Akses::pemilik($item, $user)) {
            abort(404, 'Data perubahan TI tidak ditemukan.');
        }
    }

    private function pastikan(bool $kondisi, string $pesan, int $kode = 403): void
    {
        if (!$kondisi) {
            abort($kode, $pesan);
        }
    }

    private function hitungStatistik($user): array
    {
        $hitung = $this->queryTerlihat($user)
            ->select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        $stats = ['total' => (int) $hitung->sum()];

        foreach (array_keys(SmkiPerubahanIt::STATUS_LABEL) as $status) {
            $stats[$status] = (int) ($hitung[$status] ?? 0);
        }

        return $stats;
    }

    // ------------------------------------------------------------------
    // Helper: penyimpanan & alur
    // ------------------------------------------------------------------

    private function isSubmit(Request $request): bool
    {
        return $request->input('aksi_simpan') === 'ajukan';
    }

    /**
     * Id pengesah dari dropdown form + nama yang diambil dari tabel users.
     * Hanya kunci yang dikirim form yang diproses.
     */
    private function dataPengesah(array $validated): array
    {
        $out = [];

        foreach (['diterima_oleh', 'disetujui_oleh', 'dilaksanakan_oleh'] as $p) {
            if (!array_key_exists("{$p}_id", $validated)) {
                continue;
            }

            $id = $validated["{$p}_id"];
            $out["{$p}_id"]   = $id ?: null;
            $out["{$p}_nama"] = $id ? User::whereKey($id)->value('name') : null;
        }

        return $out;
    }

    /**
     * Buat data baru dengan No. RFC otomatis berformat FR-015/001, FR-015/002, dst.
     */
    private function buatDenganRfc(array $data): SmkiPerubahanIt
    {
        $prefix = 'FR-015/';

        for ($percobaan = 1; $percobaan <= 5; $percobaan++) {
            $terakhir = SmkiPerubahanIt::where('no_rfc', 'like', $prefix . '%')
                ->orderByRaw('CAST(SUBSTRING(no_rfc, ?) AS UNSIGNED) DESC', [strlen($prefix) + 1])
                ->lockForUpdate()
                ->value('no_rfc');

            $urut = $terakhir ? ((int) substr($terakhir, strlen($prefix))) + 1 : 1;

            try {
                return SmkiPerubahanIt::create(array_merge($data, [
                    'no_rfc'  => $prefix . str_pad((string) $urut, 3, '0', STR_PAD_LEFT),
                    'tanggal' => now()->toDateString(),
                ]));
            } catch (UniqueConstraintViolationException $e) {
                if ($percobaan === 5) {
                    throw $e;
                }
            }
        }

        throw new RuntimeException('Gagal membuat No. RFC.');
    }

    private function simpanLampiran(SmkiPerubahanIt $item, array $files, array &$stored): void
    {
        foreach ($files as $file) {
            if (!$file || !$file->isValid()) {
                continue;
            }

            $path = $file->store("smki/perubahan-it/{$item->id}", self::DISK);
            $stored[] = $path;

            $item->lampirans()->create([
                'nama_asli' => $file->getClientOriginalName(),
                'path'      => $path,
                'mime'      => $file->getClientMimeType(),
                'ukuran'    => $file->getSize(),
            ]);
        }
    }

    private function hapusBerkas(array $paths): void
    {
        if (!empty($paths)) {
            Storage::disk(self::DISK)->delete($paths);
        }
    }

    /**
     * Ubah status ke "Diajukan" dan catat riwayat (juga untuk pengajuan ulang setelah dikembalikan).
     */
    private function terapkanPengajuan(SmkiPerubahanIt $item, $user): void
    {
        $dari = $item->status;

        $item->update([
            'status'               => SmkiPerubahanIt::STATUS_DIAJUKAN,
            'tanggal_diajukan'     => now(),
            'catatan_pengembalian' => null,
        ]);

        $this->catat(
            $item,
            $dari === SmkiPerubahanIt::STATUS_DIKEMBALIKAN ? 'ajukan_ulang' : 'ajukan',
            $dari,
            SmkiPerubahanIt::STATUS_DIAJUKAN,
            null,
            $user
        );
    }

    private function catat(SmkiPerubahanIt $item, string $aksi, ?string $dari, string $ke, ?string $catatan, $user): void
    {
        $item->riwayats()->create([
            'aksi'        => $aksi,
            'status_dari' => $dari,
            'status_ke'   => $ke,
            'catatan'     => $catatan,
            'user_id'     => $user?->id,
            'nama_user'   => $user?->name ?? 'Sistem',
        ]);
    }

    private function wajibIsi($nilai, string $field, string $pesan): void
    {
        if ($nilai === null || $nilai === '') {
            throw ValidationException::withMessages([$field => [$pesan]]);
        }
    }

    // ------------------------------------------------------------------
    // Helper: validasi
    // ------------------------------------------------------------------

    private function validatePayload(Request $request, bool $submit): array
    {
        $maks = (int) config('smki_perubahan_it.lampiran_maks_berkas', 5);
        $maksKb = (int) config('smki_perubahan_it.lampiran_maks_kb', 5120);

        $rules = array_merge($this->aturanFormulir($submit), [
            'aksi_simpan'      => ['nullable', Rule::in(['draft', 'ajukan'])],
            'lampiran'         => ['nullable', 'array', "max:{$maks}"],
            'lampiran.*'       => ['file', "max:{$maksKb}", 'mimes:pdf,docx,xlsx,png,jpg,jpeg'],
            'hapus_lampiran'   => ['nullable', 'array'],
            'hapus_lampiran.*' => ['integer'],

            // Dropdown pengesah bagian 05 (opsional; belum dikaitkan dengan hak akses)
            'diterima_oleh_id'     => ['nullable', 'integer', Rule::exists('users', 'id')],
            'disetujui_oleh_id'    => ['nullable', 'integer', Rule::exists('users', 'id')],
            'dilaksanakan_oleh_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
        ]);

        return $request->validate($rules, $this->pesanValidasi(), $this->namaAtribut());
    }

    /**
     * Saat Draft semua isian opsional; saat diajukan isian inti wajib diisi.
     */
    private function aturanFormulir(bool $submit): array
    {
        $wajib = $submit ? 'required' : 'nullable';

        return [
            'judul'                => [$wajib, 'string', 'max:255'],
            'unit_kerja_id'        => [$wajib, 'integer', Rule::exists('master_unit_kerja', 'id_unit_kerja')],
            'perangkat_daerah'     => [$wajib, 'string', 'max:255'],
            'nomor_surat_kontak'   => ['nullable', 'string', 'max:255'],
            'jenis_perubahan'      => [$wajib, Rule::in(array_keys(SmkiPerubahanIt::JENIS_PERUBAHAN))],
            'perubahan_diharapkan' => [$wajib, 'string', 'max:5000'],
            'latar_belakang'       => [$wajib, 'string', 'max:5000'],
            'rincian_perubahan'    => [$wajib, 'string', 'max:5000'],
            'tingkat_risiko'       => [$wajib, Rule::in(array_keys(SmkiPerubahanIt::TINGKAT_RISIKO))],
            'keterangan_risiko'    => ['nullable', 'string', 'max:5000'],
            'solusi_diharapkan'    => ['nullable', 'string', 'max:5000'],
            'risiko_perubahan'     => ['nullable', 'string', 'max:5000'],
            'alternatif_perubahan' => ['nullable', 'string', 'max:5000'],
            'estimasi_biaya'       => ['nullable', 'numeric', 'min:0', 'max:9999999999999'],
            'tanggal_perubahan'    => ['nullable', 'date'],
            'jam_mulai'            => ['nullable', 'date_format:H:i,H:i:s'],
            'jam_selesai'          => ['nullable', 'date_format:H:i,H:i:s'],
        ];
    }

    private function pesanValidasi(): array
    {
        return [
            'required'          => ':attribute wajib diisi.',
            'string'            => ':attribute harus berupa teks.',
            'in'                => ':attribute tidak valid.',
            'date'              => ':attribute bukan tanggal yang valid.',
            'before_or_equal'   => ':attribute tidak boleh melebihi hari ini.',
            'max.string'        => ':attribute maksimal :max karakter.',
            'max.array'         => ':attribute maksimal :max berkas.',
            'max.file'          => ':attribute maksimal :max KB per berkas.',
            'file'              => ':attribute harus berupa berkas.',
            'mimes'             => ':attribute harus berformat: :values.',
            'numeric'           => ':attribute harus berupa angka.',
            'integer'           => ':attribute tidak valid.',
            'min.numeric'       => ':attribute tidak boleh kurang dari :min.',
            'date_format'       => ':attribute harus berformat jam yang valid (JJ:MM).',
            'exists'            => ':attribute tidak ditemukan.',
            'uploaded'          => ':attribute gagal diunggah.',
        ];
    }

    private function namaAtribut(): array
    {
        return [
            'judul'                => 'Judul Perubahan',
            'unit_kerja_id'        => 'Unit Kerja',
            'perangkat_daerah'     => 'Perangkat Daerah',
            'nomor_surat_kontak'   => 'Nomor Surat / Contact Person',
            'jenis_perubahan'      => 'Jenis Perubahan',
            'perubahan_diharapkan' => 'Perubahan yang Diharapkan',
            'latar_belakang'       => 'Latar Belakang Perubahan',
            'rincian_perubahan'    => 'Rincian Perubahan',
            'tingkat_risiko'       => 'Kriteria Risiko',
            'keterangan_risiko'    => 'Keterangan Risiko',
            'solusi_diharapkan'    => 'Solusi yang Diharapkan',
            'risiko_perubahan'     => 'Risiko Perubahan',
            'alternatif_perubahan' => 'Alternatif Perubahan',
            'estimasi_biaya'       => 'Biaya Perubahan',
            'tanggal_perubahan'    => 'Tanggal Perubahan',
            'jam_mulai'            => 'Jam mulai',
            'jam_selesai'          => 'Jam selesai',
            'lampiran'             => 'Lampiran',
            'lampiran.*'           => 'Lampiran',
            'catatan'              => 'Catatan',
            'tanggal_pelaksanaan'  => 'Tanggal pelaksanaan',
            'diterima_oleh_id'     => 'Koordinator Agen',
            'disetujui_oleh_id'    => 'Penanggung Jawab Perangkat',
            'dilaksanakan_oleh_id' => 'Agen',
        ];
    }

    // ------------------------------------------------------------------
    // Helper: transformasi respons
    // ------------------------------------------------------------------

    private function transform(SmkiPerubahanIt $i, $user): array
    {
        $iso = fn ($v) => $v?->toIso8601String();

        $nameCol = SmkiPerubahanIt::UNIT_NAME_COLUMN;

        return array_merge(
            ['id' => $i->id, 'no_rfc' => $i->no_rfc, 'tanggal' => $i->tanggal?->toDateString()],
            Arr::except($i->only(SmkiPerubahanIt::FORM_FIELDS), ['estimasi_biaya', 'tanggal_perubahan', 'jam_mulai', 'jam_selesai']),
            [
                'pemohon'           => $i->pemohon,
                'unit_kerja'        => $i->unitKerja?->{$nameCol},
                'estimasi_biaya'    => $i->estimasi_biaya !== null ? (float) $i->estimasi_biaya : null,
                'tanggal_perubahan' => $i->tanggal_perubahan?->toDateString(),
                'jam_mulai'         => $i->jam_mulai ? substr($i->jam_mulai, 0, 5) : null,
                'jam_selesai'       => $i->jam_selesai ? substr($i->jam_selesai, 0, 5) : null,

                'status'       => $i->status,
                'status_label' => SmkiPerubahanIt::STATUS_LABEL[$i->status] ?? $i->status,
                'jenis_perubahan_label' => SmkiPerubahanIt::JENIS_PERUBAHAN[$i->jenis_perubahan] ?? null,
                'tingkat_risiko_label'  => SmkiPerubahanIt::TINGKAT_RISIKO[$i->tingkat_risiko] ?? null,

                'tanggal_diajukan'     => $iso($i->tanggal_diajukan),
                'catatan_pengembalian' => $i->catatan_pengembalian,
                'diterima_oleh_id'     => $i->diterima_oleh_id,
                'diterima_oleh_nama'   => $i->diterima_oleh_nama,
                'diterima_pada'        => $iso($i->diterima_pada),
                'disetujui_oleh_id'    => $i->disetujui_oleh_id,
                'disetujui_oleh_nama'  => $i->disetujui_oleh_nama,
                'disetujui_pada'       => $iso($i->disetujui_pada),
                'alasan_penolakan'     => $i->alasan_penolakan,
                'dilaksanakan_oleh_id'   => $i->dilaksanakan_oleh_id,
                'dilaksanakan_oleh_nama' => $i->dilaksanakan_oleh_nama,
                'dilaksanakan_pada'    => $iso($i->dilaksanakan_pada),
                'tanggal_pelaksanaan'  => $i->tanggal_pelaksanaan?->toDateString(),
                'catatan_pelaksanaan'  => $i->catatan_pelaksanaan,

                'created_by' => $i->created_by,
                'created_at' => $iso($i->created_at),
                'updated_at' => $iso($i->updated_at),

                'izin' => Akses::izin($i, $user),

                'lampiran' => $i->lampirans->map(fn ($l) => [
                    'id'        => $l->id,
                    'nama_asli' => $l->nama_asli,
                    'mime'      => $l->mime,
                    'ukuran'    => (int) $l->ukuran,
                ])->values(),

                'riwayat' => $i->riwayats->map(fn ($r) => [
                    'id'          => $r->id,
                    'aksi'        => $r->aksi,
                    'status_dari' => $r->status_dari,
                    'status_ke'   => $r->status_ke,
                    'catatan'     => $r->catatan,
                    'nama_user'   => $r->nama_user,
                    'waktu'       => $iso($r->created_at),
                ])->values(),
            ]
        );
    }
}