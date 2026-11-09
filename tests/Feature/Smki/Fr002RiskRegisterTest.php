<?php

namespace Tests\Feature\Smki;

use App\Models\Bidang;
use App\Models\Pegawai;
use App\Models\SmkiFr002KategoriAset;
use App\Models\SmkiFr002Risiko;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class Fr002RiskRegisterTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/smki/risk-register';

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== ':memory:') {
            throw new \RuntimeException('Tes FR-002 hanya boleh memakai SQLite :memory:.');
        }
    }

    protected function migrateFreshUsing(): array
    {
        return ['--path' => [
            'database/migrations/0001_01_01_000000_create_users_table.php',
            'database/migrations/2026_07_03_075728_create_pegawai_table.php',
            'database/migrations/2026_08_20_000001_create_bidangs_table.php',
            'database/migrations/2026_08_20_000002_create_services_table.php',
            'database/migrations/2026_08_20_000003_create_bidang_services_table.php',
            'database/migrations/2026_08_20_000004_add_bidang_id_to_users_table.php',
            'database/migrations/2026_08_20_000006_add_parent_id_to_services_table.php',
            'database/migrations/2026_11_02_000002_create_smki_fr002_risk_registers_tables.php',
            'database/migrations/2026_11_09_000001_add_lock_versions_to_fr002.php',
        ]];
    }

    public function test_migration_seeds_references_only_and_requires_login(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();
        $this->login('APTIKA');
        $this->getJson(self::URL.'/lookup')->assertOk()->assertJsonCount(5, 'data.kategoris')
            ->assertJsonCount(13, 'data.annexes')->assertJsonPath('data.header', null);
        $this->getJson(self::URL)->assertOk()->assertJsonCount(0, 'data');
        $this->assertDatabaseEmpty('smki_fr002_risk_treatment_plans');
        SmkiFr002KategoriAset::where('kode_kategori', 'E')->delete();
        (require database_path('migrations/2026_11_02_000002_create_smki_fr002_risk_registers_tables.php'))->up();
        $this->assertDatabaseCount('smki_fr002_kategori_asets', 5);
        $this->assertDatabaseCount('smki_fr002_kontrol_annex_isos', 13);
    }

    public function test_users_across_bidang_can_edit_and_delete_with_the_current_version(): void
    {
        $this->login('APTIKA');
        $risk = $this->postJson(self::URL, ['kode_annex' => 'A5.1'] + $this->risk())->assertCreated()->json('data');
        $url = self::URL.'/'.$risk['id'];

        $this->login('IKP');
        $this->getJson($url)->assertOk()->assertJsonPath('data.nama_aset_proses', 'Aset uji');
        $this->putJson($url, ['nama_aset_proses' => 'Perubahan terbaru', 'kode_annex' => null, 'lock_version' => 1] + $this->risk())
            ->assertOk()->assertJsonPath('data.lock_version', 2)->assertJsonPath('data.kode_annex', null);
        $this->putJson($url, ['lock_version' => 1] + $this->risk())->assertConflict();
        $this->deleteJson($url, ['lock_version' => 1])->assertConflict();
        $this->getJson($url)->assertJsonPath('data.nama_aset_proses', 'Perubahan terbaru');
        $this->deleteJson($url, ['lock_version' => 2])->assertOk();
        $this->deleteJson($url, ['lock_version' => 2])->assertConflict();
        $this->putJson($url, ['lock_version' => 2] + $this->risk())->assertConflict();
        $this->getJson($url)->assertNotFound();
    }

    public function test_exports_do_not_change_header_and_excel_writes_user_input_as_text(): void
    {
        $this->login('APTIKA');
        $longImpact = str_repeat('Rincian dampak uji. ', 200).'Akhir uraian dampak.';
        $this->postJson(self::URL, ['nama_aset_proses' => '=1+1', 'uraian_dampak' => $longImpact] + $this->risk())->assertCreated();
        $employee = Pegawai::create([
            'nama' => 'Pegawai uji', 'nip' => '123456789012345678', 'pangkat' => 'III/a',
            'jabatan' => 'Pengesah uji', 'role' => 'staff', 'tanggal_lahir' => '1995-01-01',
        ]);
        $header = [
            'tahun_periode' => 2026, 'ruang_lingkup' => 'Lingkup uji', 'no_revisi' => '1',
            'klasifikasi' => 'Internal', 'jabatan_pengesah' => 'Pengesah uji',
            'nama_pengesah' => '=2+2', 'nip_pengesah' => $employee->nip, 'lock_version' => 0,
        ];
        $this->postJson(self::URL.'/update-header', $header)->assertOk()->assertJsonPath('data.lock_version', 1);
        $this->login('IKP');
        $this->postJson(self::URL.'/update-header', ['no_revisi' => '2'] + $header)->assertConflict();

        $response = $this->get(self::URL.'/export-excel')->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'fr002-xlsx-');
        try {
            file_put_contents($path, $response->streamedContent());
            $book = IOFactory::createReader('Xlsx')->load($path);
            $sheet = $book->getSheetByName('Final Risk Register 2023');
            $this->assertSame('=1+1', $sheet->getCell('B11')->getValue());
            $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('B11')->getDataType());
            $this->assertSame(DataType::TYPE_FORMULA, $sheet->getCell('I11')->getDataType());
            $this->assertCount(0, $sheet->getDrawingCollection(), 'A mismatched name and NIP must not produce a specimen.');
            $book->disconnectWorksheets();
        } finally {
            unlink($path);
        }

        $pdf = $this->get(self::URL.'/export-pdf')->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $html = view('smki.fr002-pdf', [
            'header' => null, 'tahun' => 2026, 'tanggalDok' => '-', 'specimen' => null,
            'groups' => [['kategori' => SmkiFr002KategoriAset::findOrFail('A'), 'items' => SmkiFr002Risiko::all()]],
        ])->render();
        $this->assertStringContainsString('[Detail #1]', $html);
        $this->assertStringContainsString($longImpact, $html);
        $this->assertDatabaseHas('smki_fr002_headers', ['no_revisi' => '1', 'lock_version' => 1]);
    }

    private function login(string $code): void
    {
        $this->actingAs(User::factory()->create([
            'bidang_id' => Bidang::where('code', $code)->value('id'),
            'role' => 'user', 'is_active' => 1,
        ]), 'sanctum');
    }

    private function risk(): array
    {
        return [
            'kode_kategori' => 'A', 'nama_aset_proses' => 'Aset uji',
            'inherent_likelihood' => 3, 'inherent_impact' => 4,
            'current_likelihood' => 2, 'current_impact' => 2,
        ];
    }
}
