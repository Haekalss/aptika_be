<?php

namespace Database\Seeders;

use App\Models\DaftarAsetTi;
use App\Models\Pegawai;
use App\Models\SmkiBackupRestoreReport;
use App\Models\SmkiDaftarAsetVirtual;
use App\Models\SmkiDaftarAsetVirtualInstance;
use App\Models\User;
use App\Services\Smki\BackupRestoreService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SmkiBackupRestoreSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Seeder FR-030 hanya untuk local/testing.');
        }
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'sqlite' && ! in_array($connection->getConfig('host'), ['localhost', '127.0.0.1', '::1'], true)) {
            throw new RuntimeException('Seeder contoh FR-030 tidak boleh dijalankan pada database cloud/remote.');
        }
        $owner = User::with('bidang')->where('is_active', 1)->whereIn('role', ['user', 'admin'])
            ->whereHas('bidang')->orderBy('bidang_id')->orderBy('id')->first();
        if (! $owner) {
            $this->command?->warn('Tidak ada akun aktif dengan bidang. Contoh FR-030 dilewati.');

            return;
        }
        $signers = Pegawai::orderBy('id')->limit(2)->get();
        $physical = DaftarAsetTi::where('bidang_id', $owner->bidang_id)->orderBy('id')->first();
        $parents = SmkiDaftarAsetVirtual::visibleTo($owner)->where('bidang_id', $owner->bidang_id)->select('id');
        $virtual = $physical ? null : SmkiDaftarAsetVirtualInstance::whereIn('daftar_aset_virtual_id', $parents)->orderBy('id')->first();
        DB::transaction(function () use ($owner, $signers, $physical, $virtual) {
            foreach (['draft', 'final'] as $status) {
                if ($status === 'final' && ($signers->count() < 2 || $signers->contains(fn ($pegawai) => collect(['nama', 'nip', 'jabatan', 'pangkat'])->contains(fn ($field) => trim((string) $pegawai->{$field}) === '')))) {
                    $this->command?->warn('Dua pegawai lengkap belum tersedia; contoh Final dilewati.');

                    continue;
                }
                $revision = 'DEMO-FR030-'.strtoupper($status);
                // Respect user edits and soft deletions instead of recreating or overwriting demo reports.
                if (SmkiBackupRestoreReport::withTrashed()->where('bidang_id', $owner->bidang_id)->where('tahun', 2024)->where('nomor_revisi', $revision)->exists()) {
                    continue;
                }
                $final = $status === 'final';
                app(BackupRestoreService::class)->save(null, [
                    'tahun' => 2024, 'nomor_dokumen' => 'CONTOH/FR-030/'.$owner->bidang->code.'/'.strtoupper($status),
                    'nomor_revisi' => $revision, 'tanggal_terbit' => $final ? '2024-01-31' : null,
                    'pelaksana_pegawai_id' => $final ? $signers[0]->id : null,
                    'penanggung_jawab_pegawai_id' => $final ? $signers[1]->id : null,
                    'finalize' => $final,
                    'assets' => [[
                        'client_key' => $final ? 'ab030000-0000-4000-8000-000000000002' : 'ab030000-0000-4000-8000-000000000001',
                        'aset_ti_id' => $final ? $physical?->id : null, 'virtual_instance_id' => $final ? $virtual?->id : null,
                        'kode_aset' => $final ? ($physical?->kode ?? $virtual?->kode_aset ?? 'CONTOH-01') : 'CONTOH-02',
                        'nama_aset' => $final ? ($physical?->nama_aset ?? $virtual?->nama_instance ?? '[CONTOH] Server Backup') : '[CONTOH] Aset manual',
                        'entries' => [['month' => 1, 'operation' => 'backup', 'result' => $final ? 'berhasil' : 'gagal',
                            'performed_on' => $final ? '2024-01-15' : null, 'notes' => '[DATA CONTOH] Untuk uji modul FR-030, bukan hasil backup operasional.']],
                    ]],
                ], $owner);
            }
        });
        $this->command?->info('Contoh FR-030 selesai; data existing tidak ditimpa.');
    }
}
