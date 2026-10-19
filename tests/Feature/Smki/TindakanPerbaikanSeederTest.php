<?php

namespace Tests\Feature\Smki;

use App\Models\Auditor;
use App\Models\Bidang;
use App\Models\SmkiTindakanPerbaikan;
use App\Models\SmkiTindakanPerbaikanItem;
use App\Models\SmkiTindakanPerbaikanVerification;
use App\Models\SmkiUnitKerja;
use App\Models\User;
use App\Services\Smki\TindakanPerbaikanService;
use Database\Seeders\MultiBidangUserSeeder;
use Database\Seeders\SmkiTindakanPerbaikanSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use RuntimeException;
use Tests\TestCase;

class TindakanPerbaikanSeederTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');

        // Use only the relevant migrations, never the configured application database.
        foreach ([
            '0001_01_01_000000_create_users_table.php',
            '2026_08_20_000001_create_bidangs_table.php',
            '2026_08_20_000004_add_bidang_id_to_users_table.php',
            '2026_09_28_000001_create_rencana_audit_tables.php',
            '2026_09_30_000001_create_smki_laporan_audit_tables.php',
            '2026_10_14_000001_create_smki_tindakan_perbaikan_tables.php',
        ] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }

        $this->seed(MultiBidangUserSeeder::class);
        Auditor::create(['nama_auditor' => 'Auditor Contoh', 'nip_auditor' => '123']);
        SmkiUnitKerja::create(['nama_unit_kerja' => 'Bidang Aplikasi Informatika']);
    }

    public function test_it_seeds_four_valid_workflow_scenarios_per_bidang_without_creating_masters(): void
    {
        $this->seed(SmkiTindakanPerbaikanSeeder::class);

        $this->assertSame(28, SmkiTindakanPerbaikan::count());
        $this->assertSame(56, SmkiTindakanPerbaikanItem::count());
        $this->assertSame(14, SmkiTindakanPerbaikanVerification::count());
        $this->assertSame(21, User::count());
        $this->assertSame(7, Bidang::count());
        $this->assertSame(1, SmkiUnitKerja::count());
        $this->assertSame(1, Auditor::count());

        foreach (Bidang::with('users')->get() as $bidang) {
            $admin = $bidang->users->firstWhere('role', 'admin');
            $owner = $bidang->users->firstWhere('role', 'user');
            $otherAdmin = User::where('role', 'admin')->where('bidang_id', '!=', $bidang->id)->firstOrFail();
            $records = SmkiTindakanPerbaikan::with(['items', 'verifications'])->where('bidang_id', $bidang->id)->get();
            $this->assertCount(4, $records);

            foreach ($records as $record) {
                $this->assertSame($owner->id, $record->created_by);
                $this->assertSame($owner->id, $record->dilaporkan_oleh_id);
                $this->assertSame($owner->name, $record->dilaporkan_oleh_nama);
                $this->assertSame($admin->id, $record->diajukan_kepada_id);
                $this->assertSame($admin->name, $record->atasan_nama);
                $this->assertSame($bidang->name, $record->area);
                $this->assertEquals($bidang->code === 'APTIKA' ? SmkiUnitKerja::first()->id_unit_kerja : null, $record->id_unit_kerja);
                $this->assertSame(['koreksi', 'tindakan_korektif'], $record->items->pluck('jenis')->all());
                $this->assertSame([$owner->id, $owner->id], $record->items->pluck('pic_id')->all());
                $this->assertFalse(Gate::forUser($owner)->allows('verify', $record));
                $this->assertFalse(Gate::forUser($otherAdmin)->allows('verify', $record));
                $this->assertSame(str_ends_with($record->nomor_laporan, '-DIAJUKAN') || $record->verifikasi === 'Open', Gate::forUser($admin)->allows('verify', $record));

                if (str_ends_with($record->nomor_laporan, '-DRAFT')) {
                    $this->assertSame('draft', $record->status);
                    $this->assertSame(1, $record->version);
                } else {
                    $this->assertSame('submitted', $record->status);
                    $this->assertSame($record->verifikasi ? 3 : 2, $record->version);
                }

                if ($record->verifikasi) {
                    $history = $record->verifications->sole();
                    $this->assertSame($admin->id, $record->verified_by);
                    $this->assertSame($admin->id, $history->verified_by);
                    $this->assertSame($record->verifikasi, $history->verifikasi);
                    $this->assertSame(2, $history->content_version);
                } else {
                    $this->assertNull($record->verified_by);
                    $this->assertCount(0, $record->verifications);
                }

                if ($record->verifikasi === 'Close') {
                    $this->assertFalse(Gate::forUser($admin)->allows('update', $record));
                    $this->assertNotEmpty($record->akar_masalah);
                    $this->assertSame(Auditor::first()->nama_auditor, $record->auditor_nama);
                }
            }
        }
    }

    public function test_rerunning_preserves_edits_soft_deletions_accounts_and_verification_history(): void
    {
        $this->seed(SmkiTindakanPerbaikanSeeder::class);
        $owner = User::where('email', 'user@aptika.com')->firstOrFail();
        $owner->update(['password' => 'password-yang-sudah-diganti']);
        $accounts = DB::table('users')->orderBy('id')->get()->toArray();
        $service = app(TindakanPerbaikanService::class);
        $draft = SmkiTindakanPerbaikan::where('nomor_laporan', 'DEMO-FR009-APTIKA-DRAFT')->firstOrFail();
        $service->update($draft, ['version' => $draft->version, 'deskripsi' => 'Perubahan milik pengguna.'], $owner);
        $deleted = SmkiTindakanPerbaikan::where('nomor_laporan', 'DEMO-FR009-EGOV-DRAFT')->firstOrFail();
        $service->delete($deleted, $deleted->version, User::findOrFail($deleted->created_by));
        $before = SmkiTindakanPerbaikan::withTrashed()->with(['items', 'verifications'])->orderBy('id')->get()->toArray();

        $this->seed(SmkiTindakanPerbaikanSeeder::class);

        $this->assertSame($before, SmkiTindakanPerbaikan::withTrashed()->with(['items', 'verifications'])->orderBy('id')->get()->toArray());
        $this->assertEquals($accounts, DB::table('users')->orderBy('id')->get()->toArray());
        $this->assertSame(28, SmkiTindakanPerbaikan::withTrashed()->count());
        $this->assertSame(27, SmkiTindakanPerbaikan::count());
    }

    public function test_it_selects_an_active_user_instead_of_changing_an_inactive_account(): void
    {
        User::where('email', 'user@aptika.com')->update(['is_active' => 0]);

        $this->seed(SmkiTindakanPerbaikanSeeder::class);

        $owner = User::where('email', 'user2@aptika.com')->firstOrFail();
        $this->assertSame(4, SmkiTindakanPerbaikan::where('created_by', $owner->id)->count());
        $this->assertEquals(0, User::where('email', 'user@aptika.com')->value('is_active'));
    }

    public function test_missing_admin_rolls_back_all_fields_instead_of_leaving_partial_demo_data(): void
    {
        User::where('email', 'admin@plddig.com')->update(['is_active' => 0]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Bidang PLDDIG memerlukan minimal satu Admin dan satu User aktif.');

        try {
            $this->seed(SmkiTindakanPerbaikanSeeder::class);
        } finally {
            $this->assertSame(0, SmkiTindakanPerbaikan::count());
            $this->assertSame(0, SmkiTindakanPerbaikanItem::count());
            $this->assertSame(0, SmkiTindakanPerbaikanVerification::count());
        }
    }

    public function test_missing_user_is_reported_without_creating_accounts(): void
    {
        User::where('bidang_id', 3)->where('role', 'user')->update(['is_active' => 0]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Bidang APTIKA memerlukan minimal satu Admin dan satu User aktif.');

        try {
            $this->seed(SmkiTindakanPerbaikanSeeder::class);
        } finally {
            $this->assertSame(0, SmkiTindakanPerbaikan::count());
            $this->assertSame(21, User::count());
        }
    }

    public function test_missing_auditor_is_reported_without_creating_master_data(): void
    {
        Auditor::query()->delete();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Siapkan master Bidang dan Auditor');

        try {
            $this->seed(SmkiTindakanPerbaikanSeeder::class);
        } finally {
            $this->assertSame(0, SmkiTindakanPerbaikan::count());
            $this->assertSame(0, Auditor::count());
        }
    }

    public function test_demo_seeding_is_blocked_in_production(): void
    {
        app()->instance('env', 'production');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('hanya boleh dijalankan pada APP_ENV=local atau testing');

        try {
            app(SmkiTindakanPerbaikanSeeder::class)->run();
        } finally {
            $this->assertSame(0, SmkiTindakanPerbaikan::count());
        }
    }
}
