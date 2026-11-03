<?php

namespace Tests\Feature;

use App\Models\AsetTiMerek;
use App\Models\AsetTiNama;
use App\Models\AsetTiPenanggungJawab;
use App\Models\Bidang;
use App\Models\SmkiRemovableMediaReport;
use App\Models\User;
use App\Services\Smki\RemovableMediaService;
use Database\Seeders\DaftarAsetTiSeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\MultiBidangUserSeeder;
use Database\Seeders\SmkiRemovableMediaMasterSeeder;
use Database\Seeders\SmkiRemovableMediaSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class SmkiRemovableMediaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Only an isolated in-memory database; never migrate or clear the development database.
        config([
            'database.default' => 'fr025_testing',
            'database.connections.fr025_testing' => array_replace(config('database.connections.sqlite'), ['database' => ':memory:', 'url' => null]),
        ]);
        DB::purge('fr025_testing');
        Schema::create('bidangs', function (Blueprint $table) {
            $table->id();
            $table->string('code');
            $table->string('name');
            $table->timestamps();
        });
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role');
            $table->integer('bidang_id');
            $table->integer('is_active')->default(1);
            $table->string('position')->nullable();
            $table->timestamps();
        });
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->string('code');
            $table->string('name');
            $table->integer('parent_id')->nullable();
        });
        Schema::create('bidang_services', function (Blueprint $table) {
            $table->id();
            $table->integer('bidang_id');
            $table->integer('service_id');
            $table->boolean('is_enabled');
        });
        foreach (['aset_ti_namas' => 'nama_aset', 'aset_ti_mereks' => 'nama_merek', 'aset_ti_penanggung_jawabs' => 'nama_pj'] as $tableName => $column) {
            Schema::create($tableName, function (Blueprint $table) use ($column) {
                $table->id();
                $table->string($column);
                $table->timestamps();
            });
        }
        Schema::create('daftar_aset_tis', function (Blueprint $table) {
            $table->id();
            $table->integer('bidang_id');
            $table->integer('penanggung_jawab_id');
            $table->softDeletes();
        });
        (require database_path('migrations/2026_10_20_000001_create_smki_removable_media_tables.php'))->up();
        (require database_path('migrations/2026_10_25_000001_add_document_metadata_to_smki_removable_media_reports.php'))->up();
    }

    private function account(string $code = 'TEST', string $role = 'user'): User
    {
        $bidang = Bidang::firstOrCreate(['code' => $code], ['name' => 'Bidang '.$code]);

        return User::create(['name' => 'Pengguna '.$code, 'email' => uniqid().'@example.invalid', 'password' => 'test-only', 'role' => $role, 'bidang_id' => $bidang->id, 'is_active' => 1]);
    }

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'nomor_dokumen' => 'FR-025/KOM.03.05/ SANDIKAMI', 'nomor_revisi' => '1.0', 'tanggal_berlaku' => '2022-07-07',
            'tanggal_dokumen' => now()->format('Y-m-d'), 'tempat' => 'Bandung',
            'pengurus_barang_user_id' => null, 'pengurus_barang_nama' => 'Pengurus inventaris',
            'kepala_unit_user_id' => null, 'kepala_unit_nama' => 'Kepala unit',
            'items' => [[
                'nama_barang_id' => null, 'nama_barang' => 'Flashdisk', 'merek_id' => null, 'merek' => 'Merek uji',
                'jumlah' => 2, 'tahun_pengadaan' => 2024, 'pic_id' => null, 'pic_nama' => 'Pemegang inventaris',
                'kondisi' => 'baik', 'keterangan' => 'Unit operasional',
            ]], 'finalize' => false,
        ], $overrides);
    }

    public function test_crud_versions_and_soft_delete(): void
    {
        $user = $this->account();
        $this->actingAs($user, 'sanctum');
        $id = $this->postJson('/api/smki/removable-media', $this->payload())
            ->assertCreated()->assertJsonPath('data.unit_kerja_nama', 'Bidang TEST')->assertJsonPath('data.version', 1)->json('data.id');
        $this->getJson('/api/smki/removable-media?search=Flashdisk')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.total_jumlah', 2);
        $this->putJson("/api/smki/removable-media/{$id}", $this->payload(['version' => 1, 'tempat' => 'Cimahi']))
            ->assertOk()->assertJsonPath('data.version', 2)->assertJsonCount(1, 'data.items');
        $this->putJson("/api/smki/removable-media/{$id}", $this->payload(['version' => 1]))->assertStatus(409);
        $this->deleteJson("/api/smki/removable-media/{$id}", ['version' => 1])->assertStatus(409);
        $this->getJson("/api/smki/removable-media/{$id}")->assertOk()->assertJsonPath('data.tempat', 'Cimahi');
        $this->deleteJson("/api/smki/removable-media/{$id}", ['version' => 2])->assertOk();
        $this->assertSoftDeleted('smki_removable_media_reports', ['id' => $id]);
        $this->getJson("/api/smki/removable-media/{$id}/export-data")->assertNotFound();
    }

    public function test_final_reports_remain_editable_and_deletable_but_require_complete_data(): void
    {
        $this->actingAs($this->account(), 'sanctum');
        $empty = $this->payload(['tanggal_dokumen' => null, 'tempat' => null, 'pengurus_barang_nama' => null, 'kepala_unit_nama' => null, 'items' => []]);
        $id = $this->postJson('/api/smki/removable-media', $empty)->assertCreated()->json('data.id');
        $this->putJson("/api/smki/removable-media/{$id}", array_merge($empty, ['version' => 1, 'finalize' => true]))
            ->assertUnprocessable()->assertJsonValidationErrors(['items', 'tanggal_dokumen', 'pengurus_barang_nama']);
        $this->assertDatabaseHas('smki_removable_media_reports', ['id' => $id, 'version' => 1, 'status' => 'draft']);
        $this->putJson("/api/smki/removable-media/{$id}", $this->payload(['version' => 1, 'finalize' => true]))
            ->assertOk()->assertJsonPath('data.status', 'final')->assertJsonPath('data.permissions.can_update', true)->assertJsonPath('data.permissions.can_delete', true);
        $finalizedAt = SmkiRemovableMediaReport::findOrFail($id)->finalized_at->toISOString();
        $this->putJson("/api/smki/removable-media/{$id}", array_merge($empty, ['version' => 2, 'finalize' => false]))
            ->assertUnprocessable()->assertJsonValidationErrors(['items', 'tanggal_dokumen']);
        $this->travel(1)->hours();
        $this->putJson("/api/smki/removable-media/{$id}", $this->payload(['version' => 2, 'finalize' => false, 'tempat' => 'Cimahi']))
            ->assertOk()->assertJsonPath('data.status', 'final')->assertJsonPath('data.version', 3)
            ->assertJsonPath('data.finalized_at', $finalizedAt)->assertJsonPath('data.tempat', 'Cimahi');
        $this->putJson("/api/smki/removable-media/{$id}", $this->payload(['version' => 2]))->assertStatus(409);
        $this->deleteJson("/api/smki/removable-media/{$id}", ['version' => 2])->assertStatus(409);
        $this->deleteJson("/api/smki/removable-media/{$id}", ['version' => 3])->assertOk();
        $this->assertSoftDeleted('smki_removable_media_reports', ['id' => $id, 'status' => 'final']);
        $this->getJson("/api/smki/removable-media/{$id}")->assertNotFound();
        $this->travelBack();
    }

    public function test_manual_document_metadata_is_validated_persisted_and_exported(): void
    {
        $this->actingAs($this->account(), 'sanctum');
        $data = $this->payload(['nomor_dokumen' => 'FR-025/UNIT-TEST/2026', 'nomor_revisi' => 'Rev. 03', 'tanggal_berlaku' => '2026-11-01']);
        $id = $this->postJson('/api/smki/removable-media', $data)->assertCreated()
            ->assertJsonPath('data.nomor_dokumen', $data['nomor_dokumen'])->json('data.id');
        $this->getJson("/api/smki/removable-media/{$id}/export-data")->assertOk()
            ->assertJsonPath('data.template.code', $data['nomor_dokumen'])
            ->assertJsonPath('data.template.revision', 'Rev. 03')
            ->assertJsonPath('data.template.effective_date', '2026-11-01');
        foreach (['nomor_dokumen' => '', 'nomor_revisi' => str_repeat('x', 51), 'tanggal_berlaku' => '2026-02-30'] as $field => $value) {
            $this->putJson("/api/smki/removable-media/{$id}", array_replace($data, ['version' => 1, $field => $value]))
                ->assertUnprocessable()->assertJsonValidationErrors([$field]);
        }
        $this->assertDatabaseHas('smki_removable_media_reports', ['id' => $id, 'version' => 1, 'nomor_revisi' => 'Rev. 03']);
        $this->putJson("/api/smki/removable-media/{$id}", array_replace($data, ['version' => 1, 'nomor_revisi' => 'Rev. 04']))
            ->assertOk()->assertJsonPath('data.nomor_revisi', 'Rev. 04');
    }

    public function test_metadata_migration_preserves_existing_reports(): void
    {
        $migration = require database_path('migrations/2026_10_25_000001_add_document_metadata_to_smki_removable_media_reports.php');
        $migration->down();
        $user = $this->account();
        $id = DB::table('smki_removable_media_reports')->insertGetId([
            'bidang_id' => $user->bidang_id, 'created_by' => $user->id,
            'created_by_nama' => $user->name, 'unit_kerja_nama' => 'Bidang TEST', 'status' => 'final', 'version' => 7,
        ]);
        $migration->up();
        $this->assertDatabaseHas('smki_removable_media_reports', [
            'id' => $id, 'version' => 7, 'status' => 'final', 'nomor_dokumen' => 'FR-025/KOM.03.05/ SANDIKAMI',
            'nomor_revisi' => '1.0', 'tanggal_berlaku' => '2022-07-07',
        ]);
    }

    public function test_a_status_change_after_binding_cannot_bypass_final_validation(): void
    {
        $user = $this->account();
        $this->actingAs($user, 'sanctum');
        $id = $this->postJson('/api/smki/removable-media', $this->payload())->json('data.id');
        $bound = SmkiRemovableMediaReport::findOrFail($id);
        $this->putJson("/api/smki/removable-media/{$id}", $this->payload(['version' => 1, 'finalize' => true]))->assertOk();
        try {
            app(RemovableMediaService::class)->save($bound, $this->payload(['version' => 2, 'items' => []]), $user);
            $this->fail('A stale bound record must not bypass final validation.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $this->assertDatabaseHas('smki_removable_media_reports', ['id' => $id, 'version' => 2, 'status' => 'final']);
        $this->assertDatabaseCount('smki_removable_media_items', 1);
    }

    public function test_reports_are_scoped_to_owner_and_bidang_with_explicit_admin_access(): void
    {
        $owner = $this->account();
        $this->actingAs($owner, 'sanctum');
        $id = $this->postJson('/api/smki/removable-media', $this->payload(['finalize' => true]))->json('data.id');
        foreach ([$this->account(), $this->account('OTHER', 'admin')] as $other) {
            $this->actingAs($other, 'sanctum');
            $this->getJson('/api/smki/removable-media')->assertJsonPath('meta.total', 0);
            $this->getJson("/api/smki/removable-media/{$id}")->assertNotFound();
            $this->getJson("/api/smki/removable-media/{$id}/export-data")->assertNotFound();
            $this->putJson("/api/smki/removable-media/{$id}", $this->payload(['version' => 1]))->assertForbidden();
            $this->deleteJson("/api/smki/removable-media/{$id}", ['version' => 1])->assertForbidden();
        }
        foreach ([$this->account('TEST', 'admin'), $this->account('APTIKA', 'admin')] as $admin) {
            $this->actingAs($admin, 'sanctum');
            $this->getJson("/api/smki/removable-media/{$id}")->assertOk()->assertJsonPath('data.permissions.can_update', true);
        }
    }

    public function test_lookup_filters_inactive_users_and_cross_bidang_pic_ids(): void
    {
        $owner = $this->account();
        $other = $this->account('OTHER');
        $inactive = $this->account();
        $inactive->update(['is_active' => 0]);
        $pic = AsetTiPenanggungJawab::create(['nama_pj' => 'PIC bidang lain']);
        DB::table('daftar_aset_tis')->insert(['bidang_id' => $other->bidang_id, 'penanggung_jawab_id' => $pic->id]);
        $this->actingAs($owner, 'sanctum');
        $this->getJson('/api/smki/removable-media/lookup')->assertOk()->assertJsonCount(1, 'data.users')->assertJsonCount(0, 'data.pics');
        $bad = $this->payload(['kepala_unit_user_id' => $other->id]);
        $bad['items'][0]['pic_id'] = $pic->id;
        $this->postJson('/api/smki/removable-media', $bad)->assertUnprocessable()->assertJsonValidationErrors(['kepala_unit_user_id', 'items.0.pic_id']);
        $this->postJson('/api/smki/removable-media', $this->payload(['pengurus_barang_user_id' => $inactive->id]))->assertUnprocessable();
    }

    public function test_master_snapshots_export_data_and_idempotent_catalog_seeder(): void
    {
        $user = $this->account();
        $this->actingAs($user, 'sanctum');
        $this->seed(SmkiRemovableMediaMasterSeeder::class);
        $this->seed(SmkiRemovableMediaMasterSeeder::class);
        $this->assertDatabaseCount('aset_ti_namas', 6);
        $name = AsetTiNama::first();
        $brand = AsetTiMerek::create(['nama_merek' => 'Merek canonical']);
        $pic = AsetTiPenanggungJawab::create(['nama_pj' => 'PIC canonical']);
        DB::table('daftar_aset_tis')->insert(['bidang_id' => $user->bidang_id, 'penanggung_jawab_id' => $pic->id]);
        $data = $this->payload(['pengurus_barang_user_id' => $user->id]);
        $data['items'][0] = array_merge($data['items'][0], ['nama_barang_id' => $name->id, 'nama_barang' => 'Palsu', 'merek_id' => $brand->id, 'pic_id' => $pic->id]);
        $id = $this->postJson('/api/smki/removable-media', $data)->assertCreated()->assertJsonPath('data.items.0.nama_barang', $name->nama_aset)->assertJsonPath('data.items.0.pic_nama', 'PIC canonical')->json('data.id');
        $name->update(['nama_aset' => 'Nama master baru']);
        $response = $this->getJson("/api/smki/removable-media/{$id}/export-data")->assertOk()->assertJsonPath('data.items.0.nama_barang', 'Flashdisk')->assertJsonPath('data.version', 1);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertDatabaseHas('smki_removable_media_reports', ['id' => $id, 'status' => 'draft', 'version' => 1]);
    }

    public function test_invalid_payload_does_not_mutate_or_create_masters(): void
    {
        $this->actingAs($this->account(), 'sanctum');
        foreach (['jumlah' => 0, 'tahun_pengadaan' => now()->year + 1, 'kondisi' => 'hilang', 'merek_id' => 999] as $field => $value) {
            $data = $this->payload();
            $data['items'][0][$field] = $value;
            $this->postJson('/api/smki/removable-media', $data)->assertUnprocessable()->assertJsonValidationErrors(['items.0.'.$field]);
        }
        $this->postJson('/api/smki/removable-media', $this->payload(['bidang_id' => 999, 'status' => 'final']))->assertUnprocessable();
        $this->assertDatabaseCount('smki_removable_media_reports', 0);
        $this->assertDatabaseCount('aset_ti_mereks', 0);
    }

    public function test_authentication_active_account_and_service_are_required(): void
    {
        $this->getJson('/api/smki/removable-media')->assertUnauthorized();
        $user = $this->account();
        $this->actingAs($user, 'sanctum');
        DB::table('services')->insert(['id' => 16, 'code' => 'SMKI', 'name' => 'SMKI']);
        DB::table('bidang_services')->insert(['bidang_id' => $user->bidang_id, 'service_id' => 16, 'is_enabled' => false]);
        $this->getJson('/api/smki/removable-media')->assertForbidden();
        DB::table('bidang_services')->update(['is_enabled' => true]);
        $user->update(['is_active' => 0]);
        $this->getJson('/api/smki/removable-media')->assertForbidden();
    }

    public function test_business_dates_use_jakarta_even_when_server_clock_is_utc(): void
    {
        $this->travelTo(Carbon::parse('2026-12-31T18:00:00Z'));
        $this->actingAs($this->account(), 'sanctum');
        $data = $this->payload(['tanggal_dokumen' => '2027-01-01', 'finalize' => true]);
        $data['items'][0]['tahun_pengadaan'] = 2027;
        $this->postJson('/api/smki/removable-media', $data)->assertCreated();
        $this->postJson('/api/smki/removable-media', array_replace($data, ['tanggal_dokumen' => '2027-01-02']))
            ->assertUnprocessable()->assertJsonValidationErrors(['tanggal_dokumen']);
        $this->travelBack();
    }

    public function test_row_limits_unknown_roles_and_reference_ids_are_validated(): void
    {
        $user = $this->account();
        $this->actingAs($user, 'sanctum');
        $data = $this->payload();
        $data['items'] = array_fill(0, 101, $data['items'][0]);
        $this->postJson('/api/smki/removable-media', $data)->assertUnprocessable()->assertJsonValidationErrors(['items']);
        $this->postJson('/api/smki/removable-media', $this->payload(['items' => [['id' => 999]]]))->assertUnprocessable();
        $user->update(['role' => 'unknown']);
        $this->getJson('/api/smki/removable-media')->assertForbidden();
        $this->getJson('/api/smki/removable-media/lookup')->assertForbidden();
        $this->assertDatabaseCount('smki_removable_media_reports', 0);
    }

    public function test_sample_seeder_reuses_masters_respects_scope_and_preserves_edited_deleted_reports(): void
    {
        $owner = $this->account();
        $admin = $this->account('TEST', 'admin');
        $other = $this->account('OTHER');
        $inactive = $this->account();
        $inactive->update(['is_active' => 0]);
        $unknown = $this->account();
        $unknown->update(['role' => 'unknown']);
        $brand = AsetTiMerek::create(['nama_merek' => 'Merek existing']);
        $pic = AsetTiPenanggungJawab::create(['nama_pj' => 'PIC existing bidang lain']);
        DB::table('daftar_aset_tis')->insert(['bidang_id' => $other->bidang_id, 'penanggung_jawab_id' => $pic->id]);
        $this->actingAs($owner, 'sanctum');
        $realId = $this->postJson('/api/smki/removable-media', $this->payload(['nomor_dokumen' => 'LAPORAN-ASLI']))->assertCreated()->json('data.id');
        $original = SmkiRemovableMediaReport::findOrFail($realId)->getAttributes();

        $this->seed(SmkiRemovableMediaSeeder::class);
        $this->assertDatabaseCount('smki_removable_media_reports', 7);
        $this->assertDatabaseCount('smki_removable_media_items', 16);
        $this->assertDatabaseCount('users', 5);
        $this->assertDatabaseCount('aset_ti_namas', 6);
        $this->assertDatabaseCount('aset_ti_mereks', 1);
        $this->assertDatabaseCount('aset_ti_penanggung_jawabs', 1);
        $this->assertDatabaseCount('daftar_aset_tis', 1);
        $this->assertSame($original, SmkiRemovableMediaReport::findOrFail($realId)->getAttributes());
        $this->assertFalse(SmkiRemovableMediaReport::whereIn('created_by', [$inactive->id, $unknown->id])->exists());
        $this->assertSame(3, SmkiRemovableMediaReport::visibleTo($owner)->count());
        $this->assertSame(5, SmkiRemovableMediaReport::visibleTo($admin)->count());
        $this->assertSame(2, SmkiRemovableMediaReport::visibleTo($other)->count());
        $otherFinal = SmkiRemovableMediaReport::where('created_by', $other->id)->where('status', 'final')->firstOrFail();
        $this->assertTrue($otherFinal->items->every(fn ($item) => $item->pic_id === $pic->id && $item->merek_id === $brand->id));
        $draft = SmkiRemovableMediaReport::where('created_by', $owner->id)->where('created_by_nama', 'like', '[DATA CONTOH FR-025 DRAFT]%')->firstOrFail();
        $final = SmkiRemovableMediaReport::where('created_by', $owner->id)->where('status', 'final')->firstOrFail();
        $this->assertNotNull($final->finalized_at);
        $this->assertNull($final->kepala_unit_user_id);
        $this->putJson("/api/smki/removable-media/{$final->id}", $this->payload(['version' => 1]))->assertOk()->assertJsonPath('data.status', 'final');
        $this->putJson("/api/smki/removable-media/{$draft->id}", $this->payload(['version' => 1, 'nomor_dokumen' => 'NOMOR-DIUBAH-PENGGUNA', 'finalize' => true]))->assertOk();
        $edited = $draft->fresh()->getAttributes();
        $owner->update(['name' => 'Nama akun sudah berubah']);
        $this->deleteJson("/api/smki/removable-media/{$final->id}", ['version' => 2])->assertOk();

        $this->seed(SmkiRemovableMediaSeeder::class);
        $this->assertSame(7, SmkiRemovableMediaReport::withTrashed()->count());
        $this->assertDatabaseCount('smki_removable_media_items', 13);
        $this->assertSame($edited, $draft->fresh()->getAttributes());
        $this->assertSoftDeleted('smki_removable_media_reports', ['id' => $final->id]);
        $this->assertSame($original, SmkiRemovableMediaReport::findOrFail($realId)->getAttributes());
    }

    public function test_sample_seeder_does_nothing_without_accounts_and_refuses_production(): void
    {
        $this->seed(SmkiRemovableMediaSeeder::class);
        $this->assertDatabaseCount('smki_removable_media_reports', 0);
        $this->assertDatabaseCount('aset_ti_namas', 0);
        $this->app->instance('env', 'production');
        try {
            $this->app->make(SmkiRemovableMediaSeeder::class)->run();
            $this->fail('Demo seeding must not run in production.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('local/testing', $error->getMessage());
            $this->assertDatabaseCount('smki_removable_media_reports', 0);
        } finally {
            $this->app->instance('env', 'testing');
        }
    }

    public function test_database_seeder_calls_removable_media_after_dependencies_only_outside_production(): void
    {
        $owner = $this->account();
        // Keep unrelated legacy seeders isolated; execute the real FR-025 seeder through its entry point.
        $seeder = new class extends DatabaseSeeder
        {
            public array $invokedSeeders = [];

            public function call($class, $silent = false, array $parameters = [])
            {
                foreach ((array) $class as $seederClass) {
                    $this->invokedSeeders[] = $seederClass;
                    if ($seederClass === SmkiRemovableMediaSeeder::class) {
                        parent::call($seederClass, true, $parameters);
                    }
                }

                return $this;
            }
        };
        $seeder->setContainer($this->app);
        $seeder->__invoke();
        $calls = $seeder->invokedSeeders;
        $this->assertContains(SmkiRemovableMediaSeeder::class, $calls);
        $this->assertContains(MultiBidangUserSeeder::class, $calls);
        $this->assertContains(DaftarAsetTiSeeder::class, $calls);
        $index = array_search(SmkiRemovableMediaSeeder::class, $calls, true);
        $this->assertLessThan($index, array_search(MultiBidangUserSeeder::class, $calls, true));
        $this->assertLessThan($index, array_search(DaftarAsetTiSeeder::class, $calls, true));
        $this->assertSame(2, SmkiRemovableMediaReport::visibleTo($owner)->count());
        $this->assertDatabaseCount('smki_removable_media_items', 5);

        $seeder->invokedSeeders = [];
        $this->app->instance('env', 'production');
        try {
            $seeder->__invoke();
            $this->assertNotContains(SmkiRemovableMediaSeeder::class, $seeder->invokedSeeders);
            $this->assertDatabaseCount('smki_removable_media_reports', 2);
        } finally {
            $this->app->instance('env', 'testing');
        }
    }
}
