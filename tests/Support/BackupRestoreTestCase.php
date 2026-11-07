<?php

namespace Tests\Support;

use App\Models\Bidang;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

abstract class BackupRestoreTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'fr030_test', 'database.connections.fr030_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
        ]]);
        DB::purge('fr030_test');
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        Schema::create('bidangs', function (Blueprint $t) {
            $t->id();
            $t->string('code');
            $t->string('name');
            $t->timestamps();
        });
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('email');
            $t->string('password');
            $t->string('role');
            $t->foreignId('bidang_id');
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('services', function (Blueprint $t) {
            $t->id();
            $t->string('code');
            $t->string('name');
            $t->unsignedBigInteger('parent_id')->nullable();
        });
        Schema::create('bidang_services', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('bidang_id');
            $t->unsignedBigInteger('service_id');
            $t->boolean('is_enabled');
        });
        Schema::create('pegawai', function (Blueprint $t) {
            $t->id();
            $t->string('nama');
            $t->string('nip');
            $t->string('jabatan');
            $t->string('pangkat');
        });
        Schema::create('daftar_aset_tis', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('bidang_id');
            $t->string('nama_aset')->nullable();
            $t->string('kode')->nullable();
            $t->softDeletes();
        });
        Schema::create('smki_daftar_aset_virtuals', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('bidang_id');
            $t->unsignedBigInteger('created_by');
            $t->softDeletes();
        });
        Schema::create('smki_daftar_aset_virtual_instances', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('daftar_aset_virtual_id');
            $t->string('nama_instance')->nullable();
            $t->string('kode_aset')->nullable();
        });
        (require database_path('migrations/2026_11_07_000001_create_smki_backup_restore_tables.php'))->up();
        DB::table('pegawai')->insert([
            ['id' => 1, 'nama' => 'Budi', 'nip' => '001', 'jabatan' => 'Teknisi', 'pangkat' => 'IIIa'],
            ['id' => 2, 'nama' => 'Budi Santoso', 'nip' => '002', 'jabatan' => 'Kepala', 'pangkat' => 'IVa'],
        ]);
        $this->travelTo(now()->setDate(2026, 11, 7));
    }

    protected function account(string $code = 'TEST', string $role = 'user'): User
    {
        $bidang = Bidang::firstOrCreate(['code' => $code], ['name' => 'Bidang '.$code]);

        return User::create(['name' => 'Pengguna', 'email' => uniqid().'@example.invalid', 'password' => 'test-only', 'role' => $role, 'bidang_id' => $bidang->id, 'is_active' => 1]);
    }

    protected function payload(array $changes = []): array
    {
        return array_replace([
            'tahun' => 2026, 'nomor_dokumen' => 'FR-030/TEST', 'nomor_revisi' => '1.0', 'tanggal_terbit' => '2026-11-07',
            'pelaksana_pegawai_id' => 2, 'penanggung_jawab_pegawai_id' => 1, 'finalize' => false,
            'assets' => [['client_key' => '48c85cb0-dc80-4ef0-a134-5ae21a9c0622', 'nama_aset' => 'Switch', 'kode_aset' => 'SW01',
                'entries' => [['month' => 10, 'operation' => 'backup', 'result' => 'berhasil', 'performed_on' => '2026-10-03', 'notes' => null]]]],
        ], $changes);
    }

    protected function stripAttachments(array $assets): array
    {
        foreach ($assets as &$asset) {
            foreach ($asset['entries'] as &$entry) {
                unset($entry['attachments']);
            }
        }

        return $assets;
    }
}
