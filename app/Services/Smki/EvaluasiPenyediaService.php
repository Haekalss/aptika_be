<?php

namespace App\Services\Smki;

use App\Models\SmkiEvaluasiKriteria;
use App\Models\SmkiEvaluasiPenyedia;
use App\Models\SmkiEvaluasiPenyediaLog;
use App\Models\SmkiEvaluasiPenyediaPenilaian;
use App\Models\SmkiPenyediaJasa;
use App\Models\SmkiRekomendasiEvaluasi;
use App\Models\SmkiSkalaNilai;
use App\Models\User;
use App\Services\TteResolverService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class EvaluasiPenyediaService
{
    public function __construct(private readonly TteResolverService $tteResolver)
    {
    }

    public function create(array $data, User $user): SmkiEvaluasiPenyedia
    {
        return DB::transaction(function () use ($data, $user): SmkiEvaluasiPenyedia {
            $penyedia = $this->syncPenyedia($data);

            $record = SmkiEvaluasiPenyedia::query()->create([
                'created_by' => $user->id,
                'penyedia_id' => $penyedia->id,
                'admin_id' => null,
                'rekomendasi_id' => null,
                'no_kontrak' => trim($data['no_kontrak']),
                'nomor_dokumen' => trim($data['nomor_dokumen']),
                'nomor_revisi' => trim($data['nomor_revisi']),
                'tanggal_berlaku' => trim($data['tanggal_berlaku']),
                'sifat_pekerjaan' => $data['sifat_pekerjaan'],
                'sifat_pekerjaan_lainnya' => $this->otherWorkDescription($data),
                'total_nilai' => null,
                'status' => 'draft',
                'catatan_revisi' => null,
                'siklus' => 1,
                'tempat' => filled($data['tempat'] ?? null) ? trim($data['tempat']) : null,
                'tanggal_evaluasi' => $data['tanggal_evaluasi'],
                'version' => 1,
            ]);

            $this->appendLog($record, 'dibuat', $user, [
                'siklus' => 1,
                'catatan' => null,
                'total_nilai' => null,
                'rekomendasi_id' => null,
            ]);

            return $this->loadDetails($record);
        });
    }

    public function updateIdentifikasi(
        SmkiEvaluasiPenyedia $record,
        array $data,
        ?int $version,
        User $user
    ): SmkiEvaluasiPenyedia {
        return DB::transaction(function () use ($record, $data, $version, $user): SmkiEvaluasiPenyedia {
            $locked = $this->lock($record, $user, 'update', $version);
            if ($locked->status !== 'draft') {
                abort(409, 'Identifikasi hanya dapat diubah saat status Draft.');
            }

            $penyedia = $this->syncPenyedia($data);

            $locked->update([
                'penyedia_id' => $penyedia->id,
                'no_kontrak' => trim($data['no_kontrak']),
                'nomor_dokumen' => trim($data['nomor_dokumen']),
                'nomor_revisi' => trim($data['nomor_revisi']),
                'tanggal_berlaku' => trim($data['tanggal_berlaku']),
                'sifat_pekerjaan' => $data['sifat_pekerjaan'],
                'sifat_pekerjaan_lainnya' => $this->otherWorkDescription($data),
                'tanggal_evaluasi' => $data['tanggal_evaluasi'],
                'tempat' => filled($data['tempat'] ?? null) ? trim($data['tempat']) : null,
                'version' => $locked->version + 1,
            ]);

            return $this->loadDetails($locked);
        });
    }

    public function savePenilaian(
        SmkiEvaluasiPenyedia $record,
        array $items,
        ?int $version,
        User $user
    ): SmkiEvaluasiPenyedia {
        return DB::transaction(function () use ($record, $items, $version, $user): SmkiEvaluasiPenyedia {
            $locked = $this->lock($record, $user, 'update', $version);
            $this->assertEditable($locked, 'Penilaian tidak dapat diubah pada status ini.');

            $criteriaIds = SmkiEvaluasiKriteria::query()->ordered()->pluck('id')->map(fn ($id) => (int) $id)->all();
            $submittedIds = collect($items)->pluck('kriteria_id')->map(fn ($id) => (int) $id)->all();
            if (
                count($items) !== count($criteriaIds)
                || count(array_unique($submittedIds)) !== count($criteriaIds)
                || array_diff($criteriaIds, $submittedIds) !== []
            ) {
                throw ValidationException::withMessages([
                    'penilaian' => ['Seluruh 4 kriteria wajib dinilai sebelum perhitungan.'],
                ]);
            }

            foreach ($items as $item) {
                $criterionId = (int) $item['kriteria_id'];
                $scale = SmkiSkalaNilai::query()->find($item['skala_id']);
                if (!$scale) {
                    throw ValidationException::withMessages([
                        'penilaian' => ['Skala penilaian tidak ditemukan.'],
                    ]);
                }

                SmkiEvaluasiPenyediaPenilaian::query()->updateOrCreate(
                    ['evaluasi_id' => $locked->id, 'kriteria_id' => $criterionId],
                    ['skala_id' => $scale->id, 'skor' => $scale->skor],
                );
            }

            SmkiEvaluasiPenyediaPenilaian::query()
                ->where('evaluasi_id', $locked->id)
                ->whereNotIn('kriteria_id', $criteriaIds)
                ->delete();

            $locked->update(['version' => $locked->version + 1]);

            return $this->loadDetails($locked);
        });
    }

    public function hitung(
        SmkiEvaluasiPenyedia $record,
        ?int $version,
        User $user
    ): SmkiEvaluasiPenyedia {
        return DB::transaction(function () use ($record, $version, $user): SmkiEvaluasiPenyedia {
            $locked = $this->lock($record, $user, 'update', $version);
            $this->assertEditable($locked, 'Penilaian tidak dapat diubah pada status ini.');

            $criteriaCount = SmkiEvaluasiKriteria::query()->count();
            $scores = $locked->penilaian()->get();
            if ($criteriaCount === 0 || $scores->count() !== $criteriaCount) {
                throw ValidationException::withMessages([
                    'penilaian' => ['Seluruh 4 kriteria wajib dinilai sebelum perhitungan.'],
                ]);
            }

            $total = (int) $scores->sum('skor');
            $recommendation = SmkiRekomendasiEvaluasi::forTotal($total);
            if (!$recommendation) {
                throw ValidationException::withMessages([
                    'penilaian' => ['Rentang rekomendasi tidak ditemukan.'],
                ]);
            }

            $locked->update([
                'total_nilai' => $total,
                'rekomendasi_id' => $recommendation->id,
                'version' => $locked->version + 1,
            ]);

            return $this->loadDetails($locked);
        });
    }

    /**
     * Pembuat laporan yang mengajukan. Evaluator adalah perwakilan vendor
     * (tanpa akun): snapshot TTE-nya dicatat dari master penyedia dan
     * dianggap sudah ditandatangani saat pengajuan ini berhasil.
     */
    public function ajukan(
        SmkiEvaluasiPenyedia $record,
        int $adminId,
        ?int $version,
        User $user
    ): SmkiEvaluasiPenyedia {
        return DB::transaction(function () use ($record, $adminId, $version, $user): SmkiEvaluasiPenyedia {
            $locked = $this->lock($record, $user, 'ajukan', $version);
            $this->assertEditable($locked, 'Evaluasi tidak dapat diajukan pada status ini.');

            $admin = User::query()
                ->whereKey($adminId)
                ->where('role', 'admin')
                ->where('is_active', 1)
                ->first();
            if (!$admin) {
                throw ValidationException::withMessages([
                    'admin_id' => ['Atasan harus merupakan akun Admin yang aktif.'],
                ]);
            }

            if ($locked->total_nilai === null) {
                abort(409, 'Hitung nilai terlebih dahulu sebelum mengajukan.');
            }

            $scores = $locked->penilaian()->with(['kriteria', 'skala'])->get();
            $criteriaCount = SmkiEvaluasiKriteria::query()->count();
            if ($criteriaCount === 0 || $scores->count() !== $criteriaCount) {
                throw ValidationException::withMessages([
                    'penilaian' => ['Seluruh 4 kriteria wajib dinilai sebelum pengajuan.'],
                ]);
            }

            // Recompute so any autosaved score cannot leave a stale result in the submission.
            $total = (int) $scores->sum('skor');
            $recommendation = SmkiRekomendasiEvaluasi::forTotal($total);
            if (!$recommendation) {
                throw ValidationException::withMessages([
                    'penilaian' => ['Rentang rekomendasi tidak ditemukan.'],
                ]);
            }

            $evaluatorSigner = $this->vendorSignerSnapshot($locked);
            $adminAssignment = $this->userSnapshot($admin);

            $previouslyReturned = SmkiEvaluasiPenyediaLog::query()
                ->where('evaluasi_id', $locked->id)
                ->where('aksi', 'dikembalikan')
                ->exists();
            $action = $previouslyReturned ? 'diajukan_ulang' : 'diajukan';
            $cycle = (int) $locked->siklus + ($previouslyReturned ? 1 : 0);

            $locked->update([
                'admin_id' => $admin->id,
                'status' => 'diajukan',
                'catatan_revisi' => null,
                'total_nilai' => $total,
                'rekomendasi_id' => $recommendation->id,
                'siklus' => $cycle,
                'ttd_evaluator_nama' => $evaluatorSigner['nama'],
                'ttd_evaluator_nip' => $evaluatorSigner['nip'],
                'ttd_evaluator_jabatan' => $evaluatorSigner['jabatan'],
                'tanggal_ttd_evaluator' => now(),
                'version' => $locked->version + 1,
            ]);

            $scoreSnapshot = $scores->map(fn (SmkiEvaluasiPenyediaPenilaian $item) => [
                'kriteria_id' => (int) $item->kriteria_id,
                'skala_id' => (int) $item->skala_id,
                'skor' => (int) $item->skor,
                'kriteria_nama' => $item->kriteria?->nama_kriteria,
                'urutan' => $item->kriteria ? (int) $item->kriteria->urutan : null,
                'skala_label' => $item->skala?->label,
            ])->values()->all();

            $this->appendLog($locked, $action, $user, [
                'siklus' => $cycle,
                'catatan' => null,
                'total_nilai' => $total,
                'rekomendasi_id' => $recommendation->id,
                'penilaian_snapshot' => $scoreSnapshot,
                'tte_snapshot' => [
                    'evaluator' => $evaluatorSigner + ['tanggal' => $locked->tanggal_ttd_evaluator?->toISOString()],
                    'admin_assignment' => $adminAssignment,
                ],
            ]);

            return $this->loadDetails($locked);
        });
    }

    public function setujui(
        SmkiEvaluasiPenyedia $record,
        ?int $version,
        User $user
    ): SmkiEvaluasiPenyedia {
        return DB::transaction(function () use ($record, $version, $user): SmkiEvaluasiPenyedia {
            $locked = $this->lock($record, $user, 'approve', $version);
            if ($locked->status !== 'diajukan') {
                abort(409, 'Evaluasi tidak sedang menunggu persetujuan.');
            }

            $signer = $this->signerSnapshot($user);
            $signedAt = now();

            $locked->update([
                'status' => 'selesai',
                'ttd_admin_nama' => $signer['nama'],
                'ttd_admin_nip' => $signer['nip'],
                'ttd_admin_jabatan' => $signer['jabatan'],
                'tanggal_ttd_admin' => $signedAt,
                'version' => $locked->version + 1,
            ]);

            $this->appendLog($locked, 'disetujui', $user, [
                'siklus' => $locked->siklus,
                'catatan' => null,
                'total_nilai' => $locked->total_nilai,
                'rekomendasi_id' => $locked->rekomendasi_id,
                'tte_snapshot' => [
                    'admin' => $signer + ['tanggal' => $signedAt->toISOString()],
                ],
            ]);

            return $this->loadDetails($locked);
        });
    }

    public function kembalikan(
        SmkiEvaluasiPenyedia $record,
        string $catatan,
        ?int $version,
        User $user
    ): SmkiEvaluasiPenyedia {
        return DB::transaction(function () use ($record, $catatan, $version, $user): SmkiEvaluasiPenyedia {
            $locked = $this->lock($record, $user, 'approve', $version);
            if ($locked->status !== 'diajukan') {
                abort(409, 'Evaluasi tidak sedang menunggu persetujuan.');
            }

            $catatan = trim($catatan);
            if ($catatan === '') {
                throw ValidationException::withMessages([
                    'catatan' => ['Catatan tinjauan wajib diisi saat mengembalikan evaluasi.'],
                ]);
            }

            $locked->update([
                'status' => 'revisi',
                'catatan_revisi' => $catatan,
                'version' => $locked->version + 1,
            ]);

            $this->appendLog($locked, 'dikembalikan', $user, [
                'siklus' => $locked->siklus,
                'catatan' => $catatan,
                'total_nilai' => $locked->total_nilai,
                'rekomendasi_id' => $locked->rekomendasi_id,
                'tte_snapshot' => ['reviewer' => $this->userSnapshot($user)],
            ]);

            return $this->loadDetails($locked);
        });
    }

    public function delete(SmkiEvaluasiPenyedia $record, int $version, User $user): void
    {
        DB::transaction(function () use ($record, $version, $user): void {
            $locked = $this->lock($record, $user, 'delete', $version);
            if ($locked->status !== 'draft') {
                abort(409, 'Hanya evaluasi berstatus Draft yang dapat dihapus.');
            }

            $locked->delete();
        });
    }

    public function lookupPenyedia(?string $query = null): EloquentCollection
    {
        $providers = SmkiPenyediaJasa::query()->orderBy('id');
        if (filled($query)) {
            $providers->where('nama_penyedia', 'like', '%' . trim($query) . '%');
        }

        return $providers->limit(8)->get();
    }

    public function createPenyedia(array $data): SmkiPenyediaJasa
    {
        return SmkiPenyediaJasa::query()->create([
            'nama_penyedia' => trim($data['nama_penyedia']),
            'contact_person' => filled($data['contact_person'] ?? null) ? trim($data['contact_person']) : null,
            'email' => filled($data['email'] ?? null) ? trim($data['email']) : null,
        ]);
    }

    private function lock(
        SmkiEvaluasiPenyedia $record,
        User $user,
        string $ability,
        ?int $version
    ): SmkiEvaluasiPenyedia {
        $locked = SmkiEvaluasiPenyedia::query()
            ->lockForUpdate()
            ->findOrFail($record->getKey());

        Gate::forUser($user)->authorize($ability, $locked);
        if ($version !== null && (int) $locked->version !== $version) {
            abort(409, 'Data telah berubah. Muat ulang sebelum melanjutkan.');
        }

        return $locked;
    }

    private function assertEditable(SmkiEvaluasiPenyedia $record, string $message): void
    {
        if (!in_array($record->status, ['draft', 'revisi'], true)) {
            abort(409, $message);
        }
    }

    private function syncPenyedia(array $data): SmkiPenyediaJasa
    {
        $providerId = $data['penyedia_id'] ?? null;
        if (filled($providerId)) {
            $provider = SmkiPenyediaJasa::query()->find($providerId);
            if (!$provider) {
                throw ValidationException::withMessages([
                    'penyedia_id' => ['Penyedia jasa tidak ditemukan.'],
                ]);
            }

            $provider->update([
                'contact_person' => trim($data['contact_person']),
                'email' => trim($data['email']),
            ]);

            return $provider;
        }

        $providerName = trim((string) ($data['penyedia_nama'] ?? ''));
        if ($providerName === '') {
            throw ValidationException::withMessages([
                'penyedia_id' => ['Penyedia jasa wajib dipilih atau diisi.'],
            ]);
        }

        return SmkiPenyediaJasa::query()->create([
            'nama_penyedia' => $providerName,
            'contact_person' => trim($data['contact_person']),
            'email' => trim($data['email']),
        ]);
    }

    private function otherWorkDescription(array $data): ?string
    {
        return $data['sifat_pekerjaan'] === 'lainnya'
            ? trim((string) ($data['sifat_pekerjaan_lainnya'] ?? ''))
            : null;
    }

    private function signerSnapshot(User $user): array
    {
        $pegawai = $this->tteResolver->find('', $user->name);

        return [
            'id' => (int) $user->id,
            'nama' => $user->name,
            'nip' => $pegawai?->nip,
            'jabatan' => $user->position ?: $pegawai?->jabatan,
        ];
    }

    /**
     * Snapshot TTE Evaluator dari master penyedia. Vendor tidak punya NIP
     * maupun jabatan di sistem, sehingga NIP dikosongkan dan jabatan diisi
     * label baku "Perwakilan Penyedia Jasa".
     */
    private function vendorSignerSnapshot(SmkiEvaluasiPenyedia $record): array
    {
        $penyedia = $record->penyedia()->first();

        return [
            'id' => $penyedia ? (int) $penyedia->id : null,
            'nama' => $penyedia?->contact_person,
            'nip' => null,
            'jabatan' => 'Perwakilan Penyedia Jasa',
            'email' => $penyedia?->email,
        ];
    }

    private function userSnapshot(User $user): array
    {
        return [
            'id' => (int) $user->id,
            'nama' => $user->name,
            'jabatan' => $user->position,
        ];
    }

    private function appendLog(
        SmkiEvaluasiPenyedia $record,
        string $action,
        User $actor,
        array $attributes
    ): SmkiEvaluasiPenyediaLog {
        return SmkiEvaluasiPenyediaLog::query()->create(array_merge([
            'evaluasi_id' => $record->id,
            'aksi' => $action,
            'siklus' => $record->siklus,
            'catatan' => null,
            'total_nilai' => $record->total_nilai,
            'rekomendasi_id' => $record->rekomendasi_id,
            'user_id' => $actor->id,
            'penilaian_snapshot' => null,
            'tte_snapshot' => null,
        ], $attributes));
    }

    private function loadDetails(SmkiEvaluasiPenyedia $record): SmkiEvaluasiPenyedia
    {
        return $record->fresh([
            'penyedia',
            'creator',
            'admin',
            'rekomendasi',
            'penilaian.kriteria',
            'penilaian.skala',
        ]);
    }
}
