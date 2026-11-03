<?php

namespace Tests\Feature;

use App\Models\Bidang;
use App\Models\SmkiFormulirAsetInformasi;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class SmkiAsetInformasiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // This connection is always isolated; no development or hosted database is migrated.
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('t', 32)),
            'database.default' => 'fr028_testing',
            'database.connections.fr028_testing' => array_replace(
                config('database.connections.sqlite'),
                ['database' => ':memory:', 'url' => null]
            ),
        ]);
        DB::purge('fr028_testing');

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
            $table->unsignedBigInteger('bidang_id');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->string('code');
            $table->string('name');
            $table->unsignedBigInteger('parent_id')->nullable();
        });
        Schema::create('bidang_services', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('bidang_id');
            $table->unsignedBigInteger('service_id');
            $table->boolean('is_enabled');
        });

        (require database_path('migrations/2026_10_25_000001_create_smki_aset_informasi_tables.php'))->up();
    }

    private function account(string $code = 'TEST', string $role = 'user'): User
    {
        $bidang = Bidang::firstOrCreate(['code' => $code], ['name' => 'Bidang '.$code]);

        return User::create([
            'name' => 'Pengguna '.$code, 'email' => uniqid().'@example.invalid',
            'password' => 'test-only', 'role' => $role, 'bidang_id' => $bidang->id,
            'is_active' => true,
        ]);
    }

    private function payload(): array
    {
        return [
            'no_formulir' => 'FR-028/TEST', 'revisi' => '1.0', 'tanggal' => '2026-11-03',
            'bidang_nama' => 'TEST', 'klasifikasi_dokumen' => 'Dokumen Terbatas',
            'unit_kerja_details' => [[
                'kode_aset' => 'DOC-001', 'nama_aset' => 'Dokumen rahasia',
                'klasifikasi' => 'Rahasia', 'format_penyimpanan' => 'Softcopy',
            ]],
            'intangible_details' => [[
                'nama_aset' => 'Reputasi layanan', 'kerahasiaan' => 1,
                'integritas' => 2, 'ketersediaan' => 3,
            ]],
        ];
    }

    public function test_crud_search_statistics_and_soft_delete(): void
    {
        $user = $this->account();
        $this->actingAs($user, 'sanctum');
        $id = $this->postJson('/api/smki/aset-informasi', $this->payload())
            ->assertCreated()->assertJsonPath('data.bidang_id', $user->bidang_id)
            ->assertJsonPath('data.intangible_details.0.nilai', 3)
            ->assertJsonPath('data.intangible_details.0.keterangan', 'Tinggi')->json('data.id');

        $this->getJson('/api/smki/aset-informasi?search=Dokumen%20rahasia')
            ->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.unit_kerja_details_count', 1)
            ->assertJsonPath('stats.total_dokumen', 1)->assertJsonPath('stats.total_unit_kerja', 1)
            ->assertJsonPath('stats.total_intangible', 1)->assertJsonPath('stats.total_rahasia', 1);
        $this->getJson('/api/smki/aset-informasi?search=tidak-ditemukan')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson("/api/smki/aset-informasi/{$id}")->assertOk()->assertJsonCount(1, 'data.unit_kerja_details');

        $updated = $this->payload();
        $updated['unit_kerja_details'][0]['nama_aset'] = 'Dokumen diperbarui';
        $updated['intangible_details'][0]['ketersediaan'] = 1;
        $this->putJson("/api/smki/aset-informasi/{$id}", $updated)->assertOk()
            ->assertJsonPath('data.unit_kerja_details.0.nama_aset', 'Dokumen diperbarui')
            ->assertJsonPath('data.intangible_details.0.nilai', 2)
            ->assertJsonPath('data.intangible_details.0.keterangan', 'Sedang');
        $this->assertDatabaseCount('smki_aset_informasi_unit_kerjas', 1);
        $this->assertDatabaseCount('smki_aset_informasi_intangibles', 1);

        $this->deleteJson("/api/smki/aset-informasi/{$id}")->assertOk();
        $this->assertSoftDeleted('smki_formulir_aset_informasis', ['id' => $id]);
        $this->assertDatabaseCount('smki_aset_informasi_unit_kerjas', 0);
        $this->assertDatabaseCount('smki_aset_informasi_intangibles', 0);
        $this->getJson("/api/smki/aset-informasi/{$id}")->assertNotFound();
        $this->getJson("/api/smki/aset-informasi/{$id}/export-excel")->assertNotFound();
    }

    public function test_optional_labels_can_be_empty_without_database_errors(): void
    {
        $this->actingAs($this->account(), 'sanctum');
        $data = array_replace($this->payload(), ['bidang_nama' => '', 'klasifikasi_dokumen' => null]);
        $id = $this->postJson('/api/smki/aset-informasi', $data)->assertCreated()
            ->assertJsonPath('data.bidang_nama', 'Bidang APTIKA')
            ->assertJsonPath('data.klasifikasi_dokumen', 'Dokumen Terbatas')->json('data.id');
        $this->putJson("/api/smki/aset-informasi/{$id}", $data)->assertOk()
            ->assertJsonPath('data.bidang_nama', 'Bidang APTIKA')
            ->assertJsonPath('data.klasifikasi_dokumen', 'Dokumen Terbatas');
    }

    public function test_other_bidang_cannot_access_records_but_aptika_admin_can(): void
    {
        $this->actingAs($this->account('A'), 'sanctum');
        $id = $this->postJson('/api/smki/aset-informasi', $this->payload())->assertCreated()->json('data.id');
        $this->actingAs($this->account('B'), 'sanctum');
        $this->getJson('/api/smki/aset-informasi')->assertOk()
            ->assertJsonPath('meta.total', 0)->assertJsonPath('stats.total_dokumen', 0)
            ->assertJsonPath('stats.total_unit_kerja', 0)->assertJsonPath('stats.total_intangible', 0);
        $this->getJson("/api/smki/aset-informasi/{$id}")->assertNotFound();
        $this->putJson("/api/smki/aset-informasi/{$id}", $this->payload())->assertNotFound();
        $this->deleteJson("/api/smki/aset-informasi/{$id}")->assertNotFound();
        $this->getJson("/api/smki/aset-informasi/{$id}/export-excel")->assertNotFound();

        $this->actingAs($this->account('APTIKA', 'admin'), 'sanctum');
        $this->getJson('/api/smki/aset-informasi')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson("/api/smki/aset-informasi/{$id}")->assertOk();
    }

    public function test_invalid_details_are_rejected_before_existing_data_is_replaced(): void
    {
        $this->actingAs($this->account(), 'sanctum');
        $id = $this->postJson('/api/smki/aset-informasi', $this->payload())->assertCreated()->json('data.id');
        $invalid = [
            ['unit_kerja_details' => null],
            ['unit_kerja_details' => 'invalid'],
            ['unit_kerja_details' => [['nama_aset' => '']]],
            ['unit_kerja_details' => [['nama_aset' => str_repeat('x', 256)]]],
            ['intangible_details' => [['nama_aset' => 'Risiko', 'kerahasiaan' => 0]]],
            ['intangible_details' => [['nama_aset' => 'Risiko', 'integritas' => 4]]],
            ['intangible_details' => [['nama_aset' => 'Risiko', 'ketersediaan' => null]]],
        ];
        foreach ($invalid as $override) {
            $data = array_replace($this->payload(), $override);
            $this->postJson('/api/smki/aset-informasi', $data)->assertUnprocessable();
            $this->putJson("/api/smki/aset-informasi/{$id}", $data)->assertUnprocessable();
        }
        $this->assertDatabaseCount('smki_formulir_aset_informasis', 1);
        $this->assertDatabaseHas('smki_aset_informasi_unit_kerjas', ['formulir_id' => $id, 'nama_aset' => 'Dokumen rahasia']);
        $this->assertDatabaseHas('smki_aset_informasi_intangibles', ['formulir_id' => $id, 'nilai' => 3]);
    }

    public function test_routes_require_login_active_account_and_enabled_service(): void
    {
        $this->getJson('/api/smki/aset-informasi')->assertUnauthorized();
        $user = $this->account();
        $user->update(['is_active' => false]);
        $this->actingAs($user, 'sanctum');
        $this->getJson('/api/smki/aset-informasi')->assertForbidden();
        $user->update(['is_active' => true]);
        $service = DB::table('services')->insertGetId(['code' => 'SMKI', 'name' => 'SMKI']);
        DB::table('bidang_services')->insert(['bidang_id' => $user->bidang_id, 'service_id' => $service, 'is_enabled' => false]);
        $this->getJson('/api/smki/aset-informasi')->assertForbidden()->assertJsonPath('error_code', 'SERVICE_DISABLED');
    }

    public function test_export_has_two_sheets_and_preserves_user_text_as_text(): void
    {
        $this->actingAs($this->account(), 'sanctum');
        $data = $this->payload();
        $data['no_formulir'] = '=1+1';
        $data['revisi'] = '=2+2';
        $data['unit_kerja_details'][0]['nama_aset'] = '=3+3';
        $data['intangible_details'][0]['nama_aset'] = '=4+4';
        $id = $this->postJson('/api/smki/aset-informasi', $data)->assertCreated()->json('data.id');
        $response = $this->getJson("/api/smki/aset-informasi/{$id}/export-excel")->assertOk();
        $response->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $path = tempnam(sys_get_temp_dir(), 'fr028-');
        try {
            file_put_contents($path, $response->streamedContent());
            $workbook = IOFactory::load($path);
            $this->assertSame(['Unit Kerja', 'Intangible'], $workbook->getSheetNames());
            foreach ([['Unit Kerja', 'C6', '=3+3'], ['Intangible', 'B2', '=1+1'], ['Intangible', 'B3', '=2+2'], ['Intangible', 'C8', '=4+4']] as [$sheet, $coordinate, $value]) {
                $cell = $workbook->getSheetByName($sheet)->getCell($coordinate);
                $this->assertSame($value, $cell->getValue());
                $this->assertSame(DataType::TYPE_STRING, $cell->getDataType());
            }
            $this->assertSame(3, $workbook->getSheetByName('Intangible')->getCell('I8')->getValue());
            $workbook->disconnectWorksheets();
        } finally {
            unlink($path);
        }
    }

    public function test_invalid_pagination_is_rejected_and_migration_preserves_existing_data(): void
    {
        $this->actingAs($this->account(), 'sanctum');
        $id = $this->postJson('/api/smki/aset-informasi', $this->payload())->assertCreated()->json('data.id');
        foreach (['per_page=0', 'per_page=-1', 'per_page=101', 'per_page=abc', 'page=0', 'search[]=array'] as $query) {
            $this->getJson('/api/smki/aset-informasi?'.$query)->assertUnprocessable();
        }
        (require database_path('migrations/2026_10_25_000001_create_smki_aset_informasi_tables.php'))->up();
        $this->assertDatabaseHas('smki_formulir_aset_informasis', ['id' => $id]);
        $this->assertDatabaseCount('smki_aset_informasi_unit_kerjas', 1);
    }

    public function test_delete_failure_rolls_back_both_detail_collections(): void
    {
        $this->actingAs($this->account(), 'sanctum');
        $id = $this->postJson('/api/smki/aset-informasi', $this->payload())->assertCreated()->json('data.id');
        SmkiFormulirAsetInformasi::deleting(function () {
            throw new \RuntimeException('Simulated delete failure');
        });
        try {
            $this->deleteJson("/api/smki/aset-informasi/{$id}")->assertStatus(500);
        } finally {
            Event::forget('eloquent.deleting: '.SmkiFormulirAsetInformasi::class);
        }
        $this->assertDatabaseHas('smki_formulir_aset_informasis', ['id' => $id, 'deleted_at' => null]);
        $this->assertDatabaseCount('smki_aset_informasi_unit_kerjas', 1);
        $this->assertDatabaseCount('smki_aset_informasi_intangibles', 1);
    }
}
