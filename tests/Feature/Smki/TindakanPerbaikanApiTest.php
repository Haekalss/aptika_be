<?php

namespace Tests\Feature\Smki;

use App\Models\Auditor;
use App\Models\SmkiTindakanPerbaikan;
use App\Models\SmkiUnitKerja;
use App\Models\User;
use App\Services\Documents\DocxToPdfService;
use App\Services\Smki\SmkiTindakanPerbaikanDocxService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TindakanPerbaikanApiTest extends TestCase
{
    private User $owner;

    private User $colleague;

    private User $admin;

    private User $otherAdmin;

    private SmkiUnitKerja $unit;

    private Auditor $auditor;

    private const URL = '/api/smki/tindakan-perbaikan';

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        // Isolate FR-009 from unrelated legacy migrations; never touch the configured production database.
        foreach ([
            '0001_01_01_000000_create_users_table.php',
            '2026_08_20_000001_create_bidangs_table.php',
            '2026_08_20_000004_add_bidang_id_to_users_table.php',
            '2026_08_20_000002_create_services_table.php',
            '2026_08_20_000003_create_bidang_services_table.php',
            '2026_09_28_000001_create_rencana_audit_tables.php',
            '2026_09_30_000001_create_smki_laporan_audit_tables.php',
            '2026_10_14_000001_create_smki_tindakan_perbaikan_tables.php',
        ] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
        Schema::table('services', fn (Blueprint $table) => $table->unsignedBigInteger('parent_id')->nullable());
        $this->owner = User::factory()->create(['name' => 'Pelapor', 'bidang_id' => 3, 'role' => 'user', 'is_active' => 1]);
        $this->colleague = User::factory()->create(['name' => 'Atasan dan PIC', 'bidang_id' => 3, 'role' => 'user', 'is_active' => 1]);
        $this->admin = User::factory()->create(['bidang_id' => 3, 'role' => 'admin', 'is_active' => 1]);
        $this->otherAdmin = User::factory()->create(['bidang_id' => 4, 'role' => 'admin', 'is_active' => 1]);
        $this->unit = SmkiUnitKerja::create(['nama_unit_kerja' => 'Bidang Aplikasi Informatika']);
        $this->auditor = Auditor::create(['nama_auditor' => 'Auditor Master', 'nip_auditor' => '123']);
    }

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'nomor_laporan' => 'FR009-'.uniqid(), 'id_unit_kerja' => $this->unit->id_unit_kerja,
            'tanggal_laporan' => '2026-10-14', 'diajukan_kepada_id' => $this->colleague->id,
            'sumber_codes' => ['audit_internal'], 'status_temuan' => 'Minor',
            'deskripsi' => 'Prosedur belum diterapkan.', 'akar_masalah' => 'Pelatihan belum diberikan.',
            'id_auditor' => $this->auditor->id_auditor, 'atasan_id' => $this->colleague->id,
            'items' => [['jenis' => 'koreksi', 'uraian' => 'Terapkan prosedur.',
                'pic_id' => $this->colleague->id, 'batas_waktu' => '2026-10-20']],
        ], $overrides);
    }

    private function draft(?array $payload = null, ?User $user = null): array
    {
        return $this->actingAs($user ?? $this->owner, 'sanctum')->postJson(self::URL, $payload ?? $this->payload())
            ->assertCreated()->json('data');
    }

    private function submitted(?array $payload = null): array
    {
        $record = $this->draft($payload);

        return $this->postJson(self::URL."/{$record['id']}/submit", ['version' => $record['version']])
            ->assertOk()->assertJsonPath('data.status', 'submitted')->json('data');
    }

    private function verification(string $decision = 'Open', int $version = 2): array
    {
        return ['version' => $version, 'verifikasi' => $decision, 'tanggal_verifikasi' => '2026-10-15',
            'catatan_verifikasi' => 'Pemeriksaan dilaksanakan.'];
    }

    public function test_authentication_active_account_and_service_gate_are_required(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();
        $inactive = User::factory()->create(['bidang_id' => 3, 'is_active' => 0]);
        $this->actingAs($inactive, 'sanctum')->getJson(self::URL)->assertForbidden();
        DB::table('services')->insert(['id' => 16, 'code' => 'SMKI', 'name' => 'SMKI']);
        DB::table('bidang_services')->insert(['service_id' => 16, 'bidang_id' => 3, 'is_enabled' => false]);
        $this->actingAs($this->owner, 'sanctum')->getJson(self::URL)->assertForbidden();
    }

    public function test_partial_draft_defaults_reporter_and_has_permissions(): void
    {
        $record = $this->draft([]);
        $this->assertSame('draft', $record['status']);
        $this->assertSame($this->owner->id, $record['created_by']);
        $this->assertSame($this->owner->name, $record['dilaporkan_oleh_nama']);
        $this->assertNull($record['verifikasi']);
        $this->assertSame([], $record['items']);
        $this->assertTrue($record['permissions']['can_delete']);
        $this->assertFalse($record['permissions']['can_verify']);
    }

    public function test_master_names_override_spoofed_snapshots_and_manual_names_are_allowed(): void
    {
        $record = $this->draft($this->payload(['area' => 'Forged unit', 'diajukan_kepada_nama' => 'Forged person', 'auditor_nama' => 'Forged auditor']));
        $this->assertSame($this->unit->nama_unit_kerja, $record['area']);
        $this->assertSame($this->colleague->name, $record['diajukan_kepada_nama']);
        $this->assertSame($this->auditor->nama_auditor, $record['auditor_nama']);
        $manual = $this->draft(['area' => 'Area eksternal', 'diajukan_kepada_nama' => 'Mitra Eksternal']);
        $this->assertSame('Area eksternal', $manual['area']);
        $this->assertSame('Mitra Eksternal', $manual['diajukan_kepada_nama']);
    }

    public function test_protected_fields_are_rejected_even_when_null(): void
    {
        foreach (['created_by', 'bidang_id', 'verified_by', 'template_version', 'status', 'verifikasi', 'version'] as $field) {
            $this->actingAs($this->owner, 'sanctum')->postJson(self::URL, [$field => null])
                ->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $record = $this->draft();
        $this->putJson(self::URL."/{$record['id']}", ['version' => 1, 'verifikasi' => 'Close'])
            ->assertUnprocessable()->assertJsonValidationErrors('verifikasi');
    }

    public function test_users_and_admins_cannot_read_modify_or_list_outside_their_scope(): void
    {
        $record = $this->draft();
        foreach ([$this->colleague, $this->otherAdmin] as $actor) {
            $this->actingAs($actor, 'sanctum')->getJson(self::URL."/{$record['id']}")->assertForbidden();
            $this->putJson(self::URL."/{$record['id']}", ['version' => 1, 'deskripsi' => 'Unauthorized'])
                ->assertForbidden();
            $this->putJson(self::URL."/{$record['id']}", ['version' => 'invalid', 'created_by' => null])
                ->assertForbidden();
            $this->deleteJson(self::URL."/{$record['id']}", ['version' => 1])->assertForbidden();
            $this->getJson(self::URL)->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('summary.total', 0);
        }
        $this->actingAs($this->admin, 'sanctum')->getJson(self::URL."/{$record['id']}")->assertOk();
        $foreignRecord = $this->draft($this->payload(['diajukan_kepada_id' => null, 'diajukan_kepada_nama' => 'Mitra',
            'atasan_id' => null, 'atasan_nama' => 'Atasan bidang lain', 'items' => []]), $this->otherAdmin);
        $this->actingAs($this->admin, 'sanctum')->getJson(self::URL."/{$foreignRecord['id']}")->assertForbidden();
    }

    public function test_lookup_and_identity_validation_only_allow_active_same_bidang_users(): void
    {
        $inactive = User::factory()->create(['bidang_id' => 3, 'is_active' => 0]);
        $lookup = $this->actingAs($this->owner, 'sanctum')->getJson(self::URL.'/lookup')->assertOk()
            ->assertJsonCount(6, 'data.sources')->assertJsonPath('data.template.revision', '3.0')->json('data');
        $ids = array_column($lookup['users'], 'id');
        $this->assertNotContains($inactive->id, $ids);
        $this->assertNotContains($this->otherAdmin->id, $ids);
        foreach ([$inactive, $this->otherAdmin] as $invalid) {
            $this->postJson(self::URL, ['diajukan_kepada_id' => $invalid->id])
                ->assertUnprocessable()->assertJsonValidationErrors('diajukan_kepada_id');
        }
        $this->postJson(self::URL, ['status_temuan' => 'Major'])->assertUnprocessable();
        $this->postJson(self::URL, ['sumber_codes' => ['unknown']])->assertUnprocessable();
    }

    public function test_item_ids_remain_stable_and_cannot_be_moved_between_reports(): void
    {
        $first = $this->draft();
        $second = $this->draft();
        $item = $first['items'][0];
        $item['uraian'] = 'Updated correction';
        $this->putJson(self::URL."/{$first['id']}", ['version' => 1, 'items' => [$item]])
            ->assertOk()->assertJsonPath('data.items.0.id', $item['id'])->assertJsonPath('data.version', 2);
        $this->putJson(self::URL."/{$second['id']}", ['version' => 1, 'items' => [$item]])
            ->assertUnprocessable()->assertJsonValidationErrors('items.0.id');
        $this->assertSame(1, SmkiTindakanPerbaikan::find($second['id'])->version);
    }

    public function test_stale_update_delete_and_submit_return_conflict_without_overwriting(): void
    {
        $record = $this->draft();
        $this->putJson(self::URL."/{$record['id']}", ['version' => 1, 'deskripsi' => 'New content'])->assertOk();
        $this->putJson(self::URL."/{$record['id']}", ['version' => 1, 'deskripsi' => 'Stale content'])->assertConflict();
        $this->deleteJson(self::URL."/{$record['id']}", ['version' => 1])->assertConflict();
        $this->postJson(self::URL."/{$record['id']}/submit", ['version' => 1])->assertConflict();
        $this->assertSame('New content', SmkiTindakanPerbaikan::find($record['id'])->deskripsi);
    }

    public function test_submission_requires_complete_report_and_complete_nonempty_actions(): void
    {
        $empty = $this->draft([]);
        $this->postJson(self::URL."/{$empty['id']}/submit", ['version' => 1])->assertUnprocessable()
            ->assertJsonValidationErrors(['nomor_laporan', 'area', 'sumber_codes', 'status_temuan', 'deskripsi']);
        $other = $this->draft($this->payload(['sumber_codes' => ['lainnya'], 'items' => [['jenis' => 'koreksi', 'uraian' => 'Partial row']]]));
        $this->postJson(self::URL."/{$other['id']}/submit", ['version' => 1])->assertUnprocessable()
            ->assertJsonValidationErrors(['sumber_lainnya', 'items.0.pic_nama', 'items.0.batas_waktu']);
    }

    public function test_only_admin_can_verify_submitted_records_and_date_notes_and_signers_are_required(): void
    {
        $record = $this->submitted($this->payload(['id_auditor' => null, 'atasan_id' => null]));
        $this->postJson(self::URL."/{$record['id']}/verify", $this->verification())->assertForbidden();
        $this->actingAs($this->admin, 'sanctum')->postJson(self::URL."/{$record['id']}/verify", ['version' => 2, 'verifikasi' => 'Open'])
            ->assertUnprocessable()->assertJsonValidationErrors(['tanggal_verifikasi', 'catatan_verifikasi']);
        $this->postJson(self::URL."/{$record['id']}/verify", $this->verification())
            ->assertUnprocessable()->assertJsonValidationErrors(['auditor_nama', 'atasan_nama']);
    }

    public function test_close_requires_root_cause_and_an_action_but_not_both_action_types(): void
    {
        $incomplete = $this->submitted($this->payload(['akar_masalah' => null, 'items' => []]));
        $this->actingAs($this->admin, 'sanctum')->postJson(self::URL."/{$incomplete['id']}/verify", $this->verification('Close'))
            ->assertUnprocessable()->assertJsonValidationErrors(['akar_masalah', 'items']);
        $complete = $this->submitted();
        $this->actingAs($this->admin, 'sanctum')->postJson(self::URL."/{$complete['id']}/verify", $this->verification('Close'))
            ->assertOk()->assertJsonPath('data.verifikasi', 'Close')->assertJsonPath('data.verified_by', $this->admin->id)
            ->assertJsonPath('data.permissions.can_update', false);
        $this->putJson(self::URL."/{$complete['id']}", ['version' => 3, 'deskripsi' => 'Changed'])->assertForbidden();
        $this->deleteJson(self::URL."/{$complete['id']}", ['version' => 3])->assertForbidden();
        $this->postJson(self::URL."/{$complete['id']}/verify", $this->verification('Open', 3))->assertForbidden();
    }

    public function test_non_audit_verification_does_not_require_an_auditor(): void
    {
        $record = $this->submitted($this->payload(['sumber_codes' => ['laporan_insiden'], 'id_auditor' => null]));
        $this->actingAs($this->admin, 'sanctum')->postJson(self::URL."/{$record['id']}/verify", $this->verification())
            ->assertOk()->assertJsonPath('data.verifikasi', 'Open');
    }

    public function test_open_edit_resubmit_preserves_append_only_verification_history(): void
    {
        $record = $this->submitted();
        $opened = $this->actingAs($this->admin, 'sanctum')->postJson(self::URL."/{$record['id']}/verify", $this->verification())
            ->assertOk()->assertJsonCount(1, 'data.verifications')->json('data');
        $this->actingAs($this->owner, 'sanctum')->putJson(self::URL."/{$record['id']}", ['version' => 3, 'deskripsi' => 'Improved content'])
            ->assertOk()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.verifikasi', null)
            ->assertJsonCount(1, 'data.verifications')->assertJsonPath('data.verifications.0.id', $opened['verifications'][0]['id']);
        $this->postJson(self::URL."/{$record['id']}/submit", ['version' => 4])->assertOk()->assertJsonPath('data.version', 5);
        $this->actingAs($this->admin, 'sanctum')->postJson(self::URL."/{$record['id']}/verify", $this->verification('Close', 5))
            ->assertOk()->assertJsonCount(2, 'data.verifications')->assertJsonPath('data.verifications.1.verifikasi', 'Open')
            ->assertJsonPath('data.verifications.0.content_version', 5);
    }

    public function test_verify_checks_concurrency_and_cannot_smuggle_content_or_owner_fields(): void
    {
        $record = $this->submitted();
        $this->actingAs($this->admin, 'sanctum')->postJson(self::URL."/{$record['id']}/verify", $this->verification('Open', 1))->assertConflict();
        $this->postJson(self::URL."/{$record['id']}/verify", [...$this->verification(), 'deskripsi' => 'Forged', 'verified_by' => null])
            ->assertUnprocessable()->assertJsonValidationErrors(['deskripsi', 'verified_by']);
    }

    public function test_delete_is_soft_delete_and_only_allowed_for_drafts_with_current_version(): void
    {
        $draft = $this->draft();
        $this->deleteJson(self::URL."/{$draft['id']}", ['version' => 1])->assertNoContent();
        $this->assertSoftDeleted('smki_tindakan_perbaikans', ['id' => $draft['id']]);
        $this->getJson(self::URL."/{$draft['id']}")->assertNotFound();
        $submitted = $this->submitted();
        $this->deleteJson(self::URL."/{$submitted['id']}", ['version' => 2])->assertForbidden();
    }

    public function test_search_source_pagination_and_summary_use_the_same_scope_and_filters(): void
    {
        $this->submitted($this->payload(['nomor_laporan' => 'MATCH-001']));
        $closed = $this->submitted($this->payload(['nomor_laporan' => 'MATCH-002']));
        $this->actingAs($this->admin, 'sanctum')->postJson(self::URL."/{$closed['id']}/verify", $this->verification('Close'))->assertOk();
        $this->draft($this->payload(['nomor_laporan' => 'OTHER-001', 'sumber_codes' => ['laporan_insiden']]));
        $this->actingAs($this->owner, 'sanctum')->getJson(self::URL.'?search=MATCH&source=audit_internal&per_page=1')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 2)->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('summary.total', 2)->assertJsonPath('summary.needs_follow_up', 1)->assertJsonPath('summary.closed', 1);
        $this->getJson(self::URL.'?status=submitted&verifikasi=Close')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $closed['id'])
            ->assertJsonPath('summary.total', 1)->assertJsonPath('summary.closed', 1);
    }

    public function test_unauthorized_exports_are_denied_before_generating_any_files(): void
    {
        $record = $this->draft();
        $this->mock(SmkiTindakanPerbaikanDocxService::class)->shouldNotReceive('generateDocx');
        $this->mock(DocxToPdfService::class)->shouldNotReceive('assertAvailable')->shouldNotReceive('convert');
        foreach ([$this->colleague, $this->otherAdmin] as $actor) {
            $this->actingAs($actor, 'sanctum')->getJson(self::URL."/{$record['id']}/export-docx")->assertForbidden();
            $this->getJson(self::URL."/{$record['id']}/export-pdf")->assertForbidden();
        }
    }

    public function test_action_order_and_submission_error_indices_match_the_two_form_groups(): void
    {
        $record = $this->draft($this->payload(['items' => [
            ['jenis' => 'tindakan_korektif', 'uraian' => 'Prosedur baru', 'urutan' => 1, 'batas_waktu' => '2026-10-20'],
            ['jenis' => 'koreksi', 'uraian' => 'Perbaikan seketika', 'urutan' => 1,
                'pic_id' => $this->colleague->id, 'batas_waktu' => '2026-10-20'],
        ]]));
        $this->assertSame('koreksi', $record['items'][0]['jenis']);
        $this->assertSame('tindakan_korektif', $record['items'][1]['jenis']);
        $this->postJson(self::URL."/{$record['id']}/submit", ['version' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('items.1.pic_nama');
    }

    public function test_authorized_pdf_without_a_converter_returns_503_before_generating_docx(): void
    {
        $record = $this->draft();
        config(['services.fr009.pdf_converter' => '']);
        $this->mock(SmkiTindakanPerbaikanDocxService::class)->shouldNotReceive('generateDocx');
        $this->getJson(self::URL."/{$record['id']}/export-pdf")->assertStatus(503);
    }

    public function test_browser_pdf_data_is_authorized_fresh_and_does_not_require_a_converter(): void
    {
        $record = $this->draft();
        config(['services.fr009.pdf_converter' => '']);
        $this->mock(DocxToPdfService::class)->shouldNotReceive('assertAvailable')->shouldNotReceive('convert');
        $this->mock(SmkiTindakanPerbaikanDocxService::class)->shouldNotReceive('generateDocx');
        SmkiTindakanPerbaikan::findOrFail($record['id'])->update(['deskripsi' => 'Konten terbaru dari server.']);

        $this->getJson(self::URL."/{$record['id']}/export-pdf-data")->assertOk()
            ->assertJsonPath('data.deskripsi', 'Konten terbaru dari server.')
            ->assertJsonPath('data.permissions.can_export', true)
            ->assertJsonPath('data.status', 'draft')->assertJsonPath('data.version', 1)
            ->assertJsonCount(1, 'data.items')->assertHeader('Cache-Control', 'no-store, private');
        $this->actingAs($this->admin, 'sanctum')->getJson(self::URL."/{$record['id']}/export-pdf-data")->assertOk();

        foreach ([$this->colleague, $this->otherAdmin] as $actor) {
            $this->actingAs($actor, 'sanctum')->getJson(self::URL."/{$record['id']}/export-pdf-data")->assertForbidden();
        }
        $this->assertDatabaseCount('smki_tindakan_perbaikans', 1);
        $this->assertDatabaseCount('smki_tindakan_perbaikan_verifications', 0);
    }

    public function test_lookup_uses_the_same_pdf_availability_check_as_the_export_endpoint(): void
    {
        $this->actingAs($this->owner, 'sanctum');
        config(['services.fr009.pdf_converter' => 'relative-converter.exe']);
        $this->getJson(self::URL.'/lookup')->assertOk()->assertJsonPath('data.export_pdf_available', false);
        config(['services.fr009.pdf_converter' => PHP_BINARY]);
        $this->getJson(self::URL.'/lookup')->assertOk()->assertJsonPath('data.export_pdf_available', true);
    }
}
