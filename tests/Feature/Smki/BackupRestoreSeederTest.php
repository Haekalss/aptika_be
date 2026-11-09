<?php

namespace Tests\Feature\Smki;

use App\Models\SmkiBackupRestoreReport;
use Database\Seeders\SmkiBackupRestoreSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Support\BackupRestoreTestCase;

class BackupRestoreSeederTest extends BackupRestoreTestCase
{
    public function test_seeder_is_idempotent_uses_existing_masters_and_keeps_user_changes(): void
    {
        $owner = $this->account();
        DB::table('daftar_aset_tis')->insert(['id' => 10, 'bidang_id' => $owner->bidang_id, 'nama_aset' => 'Server existing', 'kode' => 'SV-10']);
        $this->seed(SmkiBackupRestoreSeeder::class);
        $this->assertSame(2, SmkiBackupRestoreReport::count());
        $final = SmkiBackupRestoreReport::where('status', 'final')->with('assets.entries')->firstOrFail();
        $this->assertSame(2024, $final->tahun);
        $this->assertSame(1, $final->pelaksana_pegawai_id);
        $this->assertSame(2, $final->penanggung_jawab_pegawai_id);
        $this->assertSame(10, $final->assets[0]->aset_ti_id);
        $this->assertSame('berhasil', $final->assets[0]->entries[0]->result);
        $counts = [DB::table('smki_backup_restore_assets')->count(), DB::table('smki_backup_restore_entries')->count()];
        $final->update(['nomor_dokumen' => 'Edited by user']);
        $this->seed(SmkiBackupRestoreSeeder::class);
        $this->assertSame(2, SmkiBackupRestoreReport::count());
        $this->assertSame($counts, [DB::table('smki_backup_restore_assets')->count(), DB::table('smki_backup_restore_entries')->count()]);
        $this->assertSame('Edited by user', $final->fresh()->nomor_dokumen);
        $this->assertSame(2, DB::table('pegawai')->count());
        $this->assertSame(1, DB::table('users')->count());
    }

    public function test_seeder_rejects_production_without_writing(): void
    {
        $this->account();
        $this->app->instance('env', 'production');
        try {
            (new SmkiBackupRestoreSeeder)->run();
            $this->fail('Production seeding must be rejected');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('local/testing', $error->getMessage());
            $this->assertSame(0, SmkiBackupRestoreReport::count());
        } finally {
            $this->app->instance('env', 'testing');
        }
    }
}
