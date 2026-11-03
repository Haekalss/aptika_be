<?php

namespace Database\Seeders;

use App\Models\Auditor;
use App\Models\Bidang;
use App\Models\SmkiTindakanPerbaikan;
use App\Models\SmkiUnitKerja;
use App\Services\Smki\TindakanPerbaikanService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SmkiTindakanPerbaikanSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            throw new RuntimeException('Seeder contoh FR-009 hanya boleh dijalankan pada APP_ENV=local atau testing.');
        }

        $created = DB::transaction(function (): int {
            $bidangs = Bidang::with(['users' => fn ($query) => $query->where('is_active', 1)->orderBy('id')])
                ->orderBy('id')->get();
            $auditor = Auditor::orderBy('id_auditor')->first();

            if ($bidangs->isEmpty() || ! $auditor) {
                throw new RuntimeException('Siapkan master Bidang dan Auditor sebelum menjalankan seeder FR-009.');
            }

            $service = app(TindakanPerbaikanService::class);
            $created = 0;

            foreach ($bidangs as $bidang) {
                $admin = $bidang->users->firstWhere('role', 'admin');
                $owner = $bidang->users->firstWhere('role', 'user');

                if (! $admin || ! $owner) {
                    throw new RuntimeException("Bidang {$bidang->code} memerlukan minimal satu Admin dan satu User aktif. Tidak ada data contoh yang disimpan.");
                }

                $unit = SmkiUnitKerja::where('nama_unit_kerja', $bidang->name)->first();

                foreach (['DRAFT', 'DIAJUKAN', 'OPEN', 'CLOSE'] as $scenario) {
                    $number = "DEMO-FR009-{$bidang->code}-{$scenario}";

                    // Preserve user edits and deletions when the demo seeder is rerun.
                    if (SmkiTindakanPerbaikan::withTrashed()->where('nomor_laporan', $number)->exists()) {
                        continue;
                    }

                    $auditSource = in_array($scenario, ['DIAJUKAN', 'CLOSE'], true);
                    $deadline = $scenario === 'CLOSE' ? now()->subDay()->toDateString() : now()->addWeek()->toDateString();
                    $record = $service->create([
                        'nomor_laporan' => $number,
                        'id_unit_kerja' => $unit?->id_unit_kerja,
                        'area' => $bidang->name,
                        'tanggal_laporan' => now()->subWeek()->toDateString(),
                        'diajukan_kepada_id' => $admin->id,
                        'sumber_codes' => [$auditSource ? 'audit_internal' : ($scenario === 'OPEN' ? 'laporan_insiden' : 'usulan_saran')],
                        'status_temuan' => 'Minor',
                        'deskripsi' => "[DATA CONTOH FR-009] Evaluasi hak akses di {$bidang->name}: terdapat akun yang belum ditinjau sesuai jadwal. Skenario {$scenario}.",
                        'akar_masalah' => 'SOP belum menetapkan jadwal peninjauan dan penanggung jawab evaluasi hak akses.',
                        'id_auditor' => $auditSource ? $auditor->id_auditor : null,
                        'atasan_id' => $admin->id,
                        'items' => [
                            ['jenis' => 'koreksi', 'urutan' => 1,
                                'uraian' => 'Tinjau daftar akun dan nonaktifkan hak akses yang tidak lagi diperlukan.',
                                'pic_id' => $owner->id, 'batas_waktu' => $deadline],
                            ['jenis' => 'tindakan_korektif', 'urutan' => 1,
                                'uraian' => 'Perbarui SOP dan tetapkan evaluasi hak akses berkala beserta penanggung jawabnya.',
                                'pic_id' => $owner->id, 'batas_waktu' => $deadline],
                        ],
                    ], $owner);

                    if ($scenario !== 'DRAFT') {
                        $record = $service->submit($record, $record->version, $owner);
                    }

                    if (in_array($scenario, ['OPEN', 'CLOSE'], true)) {
                        $service->verify($record, [
                            'version' => $record->version,
                            'verifikasi' => $scenario === 'OPEN' ? 'Open' : 'Close',
                            'tanggal_verifikasi' => now()->toDateString(),
                            'catatan_verifikasi' => $scenario === 'OPEN'
                                ? '[DATA CONTOH] Koreksi awal telah ditinjau. Pembaruan SOP masih memerlukan tindak lanjut.'
                                : '[DATA CONTOH] Evaluasi akun dan pembaruan SOP telah diperiksa. Temuan dinyatakan selesai.',
                        ], $admin);
                    }

                    $created++;
                }
            }

            return $created;
        });

        $this->command?->info("FR-009: {$created} laporan contoh dibuat; laporan yang sudah ada tidak diubah.");
    }
}
