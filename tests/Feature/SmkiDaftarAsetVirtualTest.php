<?php

namespace Tests\Feature;

use App\Models\Bidang;
use App\Models\SmkiDaftarAsetVirtual;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SmkiDaftarAsetVirtualTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.url' => null,
        ]);
        DB::purge('sqlite');

        Schema::create('bidangs', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('description')->nullable();
            $table->timestamps();
        });
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role');
            $table->unsignedBigInteger('bidang_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->unsignedBigInteger('parent_id')->nullable();
        });
        Schema::create('bidang_services', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('bidang_id');
            $table->unsignedBigInteger('service_id');
            $table->boolean('is_enabled');
        });

        (require database_path('migrations/2026_11_03_000001_create_smki_daftar_aset_virtual_tables.php'))->up();
    }

    private function account(string $code = 'BIDANG-A', string $role = 'user'): User
    {
        $bidang = Bidang::firstOrCreate(['code' => $code], ['name' => 'Bidang '.$code]);

        return User::create([
            'name' => 'Pengguna '.$code,
            'email' => uniqid('fr035-', true).'@example.invalid',
            'password' => 'test-only',
            'role' => $role,
            'bidang_id' => $bidang->id,
            'is_active' => true,
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'status' => 'Draf',
            'no_dokumen' => 'FR-035/KOM.03.05/SANDIKAMI',
            'no_revisi' => '0.0',
            'tanggal_berlaku' => '2022',
            'kode_aset' => '001/TI/APTIKA',
            'nama_aset' => 'Server Virtualisasi',
            'lokasi_aset' => 'Data Center',
            'rows' => [[
                'kode_aset' => '001/VIRT/APTIKA',
                'nama_instance' => 'VM Aplikasi',
                'deskripsi' => 'Instance untuk aplikasi internal.',
                'tipe_virtualisasi' => 'Proxmox',
                'alamat' => '192.0.2.10',
                'hostname' => 'vm-app.internal',
                'spesifikasi' => '2 vCPU, RAM 4 GB',
                'pemilik' => 'Bidang A',
                'klasifikasi' => 'Internal',
            ]],
        ], $overrides);
    }

    public function test_authenticated_user_can_create_search_update_and_soft_delete_fr035(): void
    {
        $user = $this->account();
        DB::table('services')->insert(['code' => 'SMKI', 'name' => 'SMKI']);
        DB::table('bidang_services')->insert([
            'bidang_id' => $user->bidang_id,
            'service_id' => DB::table('services')->where('code', 'SMKI')->value('id'),
            'is_enabled' => true,
        ]);
        $this->actingAs($user, 'sanctum');

        $id = $this->postJson('/api/smki/daftar-aset-virtual', $this->payload([
            'status' => 'Tersimpan',
            'rows' => [
                $this->payload()['rows'][0],
                array_replace($this->payload()['rows'][0], [
                    'kode_aset' => '',
                    'nama_instance' => 'VM Kedua',
                ]),
            ],
        ]))
            ->assertCreated()
            ->assertJsonPath('data.status', 'Tersimpan')
            ->assertJsonPath('data.unit_kerja_nama', 'Bidang BIDANG-A')
            ->assertJsonPath('data.rows.1.kode_aset', '002/TI/APTIKA')
            ->assertJsonPath('data.permissions.can_update', true)
            ->json('data.id');

        $this->patchJson("/api/smki/daftar-aset-virtual/{$id}", [
            'nama_aset' => 'Server Patch',
        ])
            ->assertOk()
            ->assertJsonPath('data.nama_aset', 'Server Patch')
            ->assertJsonPath('data.status', 'Tersimpan')
            ->assertJsonCount(2, 'data.rows');

        $this->getJson('/api/smki/daftar-aset-virtual?search=VM%20Kedua&status=Tersimpan')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.instances_count', 2)
            ->assertJsonPath('data.0.rows.1.nama_instance', 'VM Kedua')
            ->assertJsonPath('data.0.rows.1.klasifikasi', 'Internal');

        $this->putJson("/api/smki/daftar-aset-virtual/{$id}", [
            'status' => 'Draf',
            'nama_aset' => 'Server Diperbarui',
        ])
            ->assertOk()
            ->assertJsonPath('data.nama_aset', 'Server Diperbarui')
            ->assertJsonPath('data.status', 'Draf')
            ->assertJsonCount(2, 'data.rows')
            ->assertJsonPath('data.rows.1.nama_instance', 'VM Kedua');

        $this->deleteJson("/api/smki/daftar-aset-virtual/{$id}")
            ->assertOk();
        $this->assertSoftDeleted('smki_daftar_aset_virtuals', ['id' => $id]);
        $this->getJson("/api/smki/daftar-aset-virtual/{$id}")->assertNotFound();
    }

    public function test_instance_asset_codes_can_be_omitted_in_drafts_and_saved_forms(): void
    {
        $this->actingAs($this->account(), 'sanctum');

        foreach (['Draf', 'Tersimpan'] as $status) {
            $row = $this->payload()['rows'][0];
            unset($row['kode_aset']);

            $this->postJson('/api/smki/daftar-aset-virtual', $this->payload([
                'status' => $status,
                'rows' => [$row],
            ]))
                ->assertCreated()
                ->assertJsonPath('data.status', $status)
                ->assertJsonPath('data.rows.0.kode_aset', '001/TI/APTIKA');
        }

        $this->assertDatabaseCount('smki_daftar_aset_virtual_instances', 2);
    }

    public function test_malformed_instance_rows_are_rejected_without_replacing_existing_data(): void
    {
        $this->actingAs($this->account(), 'sanctum');
        $id = $this->postJson('/api/smki/daftar-aset-virtual', $this->payload())
            ->assertCreated()
            ->json('data.id');

        foreach (['not-an-object', 123, null] as $row) {
            $this->putJson("/api/smki/daftar-aset-virtual/{$id}", ['rows' => [$row]])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['rows.0']);
        }

        $this->getJson("/api/smki/daftar-aset-virtual/{$id}")
            ->assertOk()
            ->assertJsonPath('data.rows.0.nama_instance', 'VM Aplikasi');
        $this->assertDatabaseCount('smki_daftar_aset_virtual_instances', 1);
    }

    public function test_saved_form_requires_complete_header_and_instance_data(): void
    {
        $this->actingAs($this->account(), 'sanctum');

        $this->postJson('/api/smki/daftar-aset-virtual', $this->payload([
            'status' => 'Tersimpan',
            'no_dokumen' => '',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['no_dokumen']);

        $this->postJson('/api/smki/daftar-aset-virtual', $this->payload([
            'status' => 'Tersimpan',
            'rows' => [[
                'nama_instance' => '',
                'klasifikasi' => '',
            ]],
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['rows']);

        $this->assertDatabaseCount('smki_daftar_aset_virtuals', 0);
    }

    public function test_users_cannot_read_or_change_other_users_forms(): void
    {
        $owner = $this->account('BIDANG-A');
        $otherUser = $this->account('BIDANG-A');
        $this->actingAs($owner, 'sanctum');
        $id = $this->postJson('/api/smki/daftar-aset-virtual', $this->payload())
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($otherUser, 'sanctum');
        $this->getJson("/api/smki/daftar-aset-virtual/{$id}")->assertNotFound();
        $this->putJson("/api/smki/daftar-aset-virtual/{$id}", $this->payload())->assertNotFound();
        $this->deleteJson("/api/smki/daftar-aset-virtual/{$id}")->assertNotFound();
    }

    public function test_visibility_is_scoped_to_creator_division_admin_and_admin_aptika(): void
    {
        $owner = $this->account('BIDANG-A');
        $this->actingAs($owner, 'sanctum');
        $id = $this->postJson('/api/smki/daftar-aset-virtual', $this->payload())
            ->assertCreated()
            ->json('data.id');

        $divisionAdmin = $this->account('BIDANG-A', 'admin');
        $this->actingAs($divisionAdmin, 'sanctum')
            ->getJson("/api/smki/daftar-aset-virtual/{$id}")
            ->assertOk()
            ->assertJsonPath('data.permissions.can_update', true);

        $otherDivisionAdmin = $this->account('BIDANG-B', 'admin');
        $this->actingAs($otherDivisionAdmin, 'sanctum')
            ->getJson("/api/smki/daftar-aset-virtual/{$id}")
            ->assertNotFound();

        $adminAptika = $this->account('APTIKA', 'admin');
        $this->actingAs($adminAptika, 'sanctum')
            ->getJson("/api/smki/daftar-aset-virtual/{$id}")
            ->assertOk()
            ->assertJsonPath('data.permissions.can_update', true);
    }

    public function test_users_with_unsupported_roles_cannot_read_forms_even_if_they_created_them(): void
    {
        $user = $this->account('BIDANG-A', 'staff');
        $form = SmkiDaftarAsetVirtual::create([
            'bidang_id' => $user->bidang_id,
            'created_by' => $user->id,
            'unit_kerja_nama' => 'Bidang A',
            'created_by_nama' => $user->name,
            'status' => 'Draf',
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/smki/daftar-aset-virtual/{$form->id}")
            ->assertForbidden();
    }

    public function test_authentication_and_smki_service_access_are_required(): void
    {
        $this->getJson('/api/smki/daftar-aset-virtual')->assertUnauthorized();

        $user = $this->account();
        DB::table('services')->insert(['code' => 'SMKI', 'name' => 'SMKI']);
        DB::table('bidang_services')->insert([
            'bidang_id' => $user->bidang_id,
            'service_id' => DB::table('services')->where('code', 'SMKI')->value('id'),
            'is_enabled' => false,
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/smki/daftar-aset-virtual')
            ->assertForbidden()
            ->assertJsonPath('error_code', 'SERVICE_DISABLED');
    }
}
