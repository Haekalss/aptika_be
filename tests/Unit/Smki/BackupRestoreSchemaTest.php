<?php

namespace Tests\Unit\Smki;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BackupRestoreSchemaTest extends TestCase
{
    public function test_migration_adds_only_fr030_tables_and_preserves_existing_magangs(): void
    {
        config([
            'database.default' => 'fr030_schema_test',
            'database.connections.fr030_schema_test' => [
                'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
                'foreign_key_constraints' => true,
            ],
        ]);
        DB::purge('fr030_schema_test');
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        foreach (['bidangs', 'users', 'pegawai', 'daftar_aset_tis', 'smki_daftar_aset_virtual_instances'] as $parent) {
            Schema::create($parent, fn (Blueprint $table) => $table->id());
        }
        Schema::create('magangs', function (Blueprint $table) {
            $table->id();
            $table->string('nama');
            $table->text('catatan')->nullable();
        });
        DB::table('magangs')->insert(['id' => 17, 'nama' => 'Data harus tetap', 'catatan' => 'Nilai asli']);
        $before = DB::table('magangs')->orderBy('id')->get()->toJson();
        $file = database_path('migrations/2026_11_07_000001_create_smki_backup_restore_tables.php');
        $this->assertFileExists($file, 'Migration FR-030 must exist');
        $migration = require $file;
        $migration->up();
        foreach (['reports', 'assets', 'entries', 'attachments'] as $suffix) {
            $this->assertTrue(Schema::hasTable('smki_backup_restore_'.$suffix));
        }
        $this->assertSame($before, DB::table('magangs')->orderBy('id')->get()->toJson());
        DB::table('bidangs')->insert(['id' => 1]);
        $reportId = DB::table('smki_backup_restore_reports')->insertGetId([
            'bidang_id' => 1, 'unit_kerja_nama' => 'Bidang A', 'tahun' => 2026,
        ]);
        $report = DB::table('smki_backup_restore_reports')->find($reportId);
        $this->assertSame('draft', $report->status);
        $this->assertSame(1, $report->version);
        $this->assertNull($report->pelaksana_pegawai_id);
        $migration->down();
        foreach (['reports', 'assets', 'entries', 'attachments'] as $suffix) {
            $this->assertFalse(Schema::hasTable('smki_backup_restore_'.$suffix));
        }
        $this->assertSame($before, DB::table('magangs')->orderBy('id')->get()->toJson());
        $this->assertTrue(Schema::hasTable('pegawai'));
    }
}
