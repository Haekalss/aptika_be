<?php

namespace Database\Seeders;

use App\Models\SmkiRemovableMediaReport;
use App\Models\User;
use App\Services\Smki\RemovableMediaLookup;
use App\Services\Smki\RemovableMediaService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class SmkiRemovableMediaSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Seeder data contoh FR-025 hanya boleh dijalankan pada environment local/testing.');
        }

        $owners = User::with('bidang')->where('is_active', 1)->whereIn('role', ['admin', 'user'])
            ->whereHas('bidang')->orderBy('id')->get();
        if ($owners->isEmpty()) {
            $this->command?->warn('Tidak ada akun aktif yang memiliki bidang. Tidak ada contoh FR-025 yang dibuat.');

            return;
        }

        $created = DB::transaction(function () use ($owners) {
            $this->call(SmkiRemovableMediaMasterSeeder::class);
            $today = now('Asia/Jakarta')->startOfDay();
            $created = 0;
            $scenarios = [
                'draft' => [['Flashdisk', 3, 'baik'], ['Kartu memori', 2, 'kurang_baik']],
                'final' => [['SSD eksternal', 1, 'baik'], ['Hard disk eksternal', 1, 'kurang_baik'], ['DVD', 5, 'rusak']],
            ];

            foreach ($owners as $owner) {
                $lookup = app(RemovableMediaLookup::class)->forUser($owner);
                $names = collect($lookup['names'])->keyBy(fn (array $option) => Str::lower($option['name']));
                $brand = collect($lookup['brands'])->first();
                $pic = collect($lookup['pics'])->first();

                foreach ($scenarios as $status => $rows) {
                    $marker = '[DATA CONTOH FR-025 '.strtoupper($status).']';
                    // The readonly author marker survives edits; withTrashed also respects user deletions.
                    if (SmkiRemovableMediaReport::withTrashed()->where('bidang_id', $owner->bidang_id)
                        ->where('created_by', $owner->id)->where('created_by_nama', 'like', $marker.'%')->exists()) {
                        continue;
                    }

                    $report = app(RemovableMediaService::class)->save(null, [
                        'nomor_dokumen' => 'CONTOH/FR-025/'.$owner->bidang->code.'/'.$owner->id.'/'.strtoupper($status),
                        'nomor_revisi' => 'CONTOH-1.0',
                        'tanggal_berlaku' => SmkiRemovableMediaReport::TEMPLATE['effective_date'],
                        'tanggal_dokumen' => $today->copy()->subDays($status === 'final' ? 14 : 7)->toDateString(),
                        'tempat' => 'Bandung',
                        'pengurus_barang_user_id' => null, 'pengurus_barang_nama' => '[CONTOH] Pengurus Barang',
                        'kepala_unit_user_id' => null, 'kepala_unit_nama' => '[CONTOH] Kepala Unit Kerja',
                        'finalize' => $status === 'final',
                        'items' => collect($rows)->map(fn (array $row, int $index) => [
                            'nama_barang_id' => $names[Str::lower($row[0])]['id'] ?? null, 'nama_barang' => $row[0],
                            'merek_id' => $brand['id'] ?? null, 'merek' => $brand['name'] ?? 'Merek contoh',
                            'jumlah' => $row[1], 'tahun_pengadaan' => $today->year - $index - 1,
                            'pic_id' => $pic['id'] ?? null, 'pic_nama' => $pic['name'] ?? '[CONTOH] PIC Inventaris',
                            'kondisi' => $row[2], 'keterangan' => '[DATA CONTOH] Untuk uji FR-025, bukan inventaris operasional sebenarnya.',
                        ])->all(),
                    ], $owner);
                    $report->updateQuietly(['created_by_nama' => Str::limit($marker.' '.$owner->name, 255, '')]);
                    $created++;
                }
            }

            return $created;
        });

        $this->command?->info("FR-025: {$created} dokumen contoh baru. Contoh existing, termasuk yang sudah dihapus, tidak ditimpa/dibuat ulang.");
    }
}
