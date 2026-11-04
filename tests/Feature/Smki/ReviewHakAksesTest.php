<?php

namespace Tests\Feature\Smki;

use App\Models\Bidang;
use App\Models\MasterSistemAplikasi;
use App\Models\Pegawai;
use App\Models\SmkiReviewHakAkses;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReviewHakAksesTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/smki/review-hak-akses';

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== ':memory:') {
            throw new \RuntimeException('Tes FR-034 hanya boleh memakai SQLite :memory:.');
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
            'database/migrations/2026_10_03_000001_create_hak_akses_ti_tables.php',
            'database/migrations/2026_10_31_000001_create_smki_review_hak_akses_table.php',
        ]];
    }

    private function login(string $code = 'APTIKA', string $role = 'user'): User
    {
        $user = User::factory()->create(['bidang_id' => Bidang::where('code', $code)->value('id'), 'role' => $role, 'is_active' => 1]);
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    private function complete(): array
    {
        $system = MasterSistemAplikasi::create(['nama_sistem' => 'Sistem FR-034']);
        $employee = Pegawai::create(['nama' => 'Pelaksana Uji', 'nip' => '123456789012345678',
            'pangkat' => 'III/a', 'jabatan' => 'Staf', 'role' => 'staff', 'tanggal_lahir' => '1995-01-01']);

        return [
            'status' => 'Tersimpan', 'documentNumber' => 'FR-034/KOM.03.05/SANDIKAMI', 'documentRevision' => '1.0',
            'documentEffectiveDate' => '2026-10-01', 'reviewDate' => '2026-10-31',
            'periodStart' => '2026-10-01', 'periodEnd' => '2026-10-30',
            'objectId' => (string) $system->getKey(), 'reviewerId' => (string) $employee->id,
            'followUp' => 'Nonaktifkan akun tidak digunakan.', 'location' => 'Bandung', 'signedAt' => '2026-10-31',
            'preparedBy' => 'Pembuat Uji', 'acknowledgedBy' => 'Pihak Mengetahui',
            'accounts' => [['id' => (string) Str::uuid(), 'username' => 'operator', 'currentAccess' => 'Read Only', 'userStatus' => 'Aktif']],
        ];
    }

    public function test_authentication_is_required(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();
        $this->postJson(self::URL, ['status' => 'Draf', 'accounts' => []])->assertUnauthorized();
    }

    public function test_every_user_and_admin_can_manage_reviews_across_bidang(): void
    {
        $writer = $this->login();
        $payload = $this->complete() + ['recorderId' => 999, 'recorderName' => 'Palsu', 'bidangId' => 999, 'objectName' => 'Palsu', 'reviewer' => 'Palsu'];
        $response = $this->postJson(self::URL, $payload)->assertCreated()
            ->assertJsonPath('data.recorderId', $writer->id)->assertJsonPath('data.bidangId', $writer->bidang_id)
            ->assertJsonPath('data.objectName', 'Sistem FR-034')->assertJsonPath('data.reviewer', 'Pelaksana Uji');
        $id = $response->json('data.id');
        foreach (['user', 'admin'] as $role) {
            $this->login('IKP', $role);
            $this->getJson(self::URL)->assertOk()->assertJsonPath('data.0.id', $id);
            $this->getJson(self::URL.'/'.$id)->assertOk();
            $payload['preparedBy'] = 'Diedit '.$role;
            $this->putJson(self::URL.'/'.$id, $payload)->assertOk()
                ->assertJsonPath('data.preparedBy', 'Diedit '.$role)->assertJsonPath('data.recorderId', $writer->id)
                ->assertJsonPath('data.bidangId', $writer->bidang_id);
        }
        $this->deleteJson(self::URL.'/'.$id)->assertOk();
        $this->getJson(self::URL.'/'.$id)->assertNotFound();
        $this->assertDatabaseCount('smki_review_hak_akses', 0);
    }

    public function test_incomplete_draft_can_be_saved_then_completed_and_edited(): void
    {
        $this->login();
        $id = $this->postJson(self::URL, ['status' => 'Draf', 'accounts' => []])->assertCreated()
            ->assertJsonPath('data.objectId', '')->json('data.id');
        $payload = $this->complete();
        $this->putJson(self::URL.'/'.$id, $payload)->assertOk()->assertJsonPath('data.status', 'Tersimpan');
        $payload['accounts'] = [['id' => (string) Str::uuid(), 'username' => 'admin-baru', 'currentAccess' => 'Admin', 'userStatus' => 'Tidak Aktif']];
        $this->putJson(self::URL.'/'.$id, $payload)->assertOk()->assertJsonCount(1, 'data.accounts')
            ->assertJsonPath('data.accounts.0.username', 'admin-baru');
        $this->assertDatabaseCount('smki_review_hak_akses', 1);
    }

    public function test_required_fields_and_accounts_are_enforced_by_server(): void
    {
        $this->login();
        $this->postJson(self::URL, ['status' => 'Tersimpan', 'accounts' => []])->assertUnprocessable()
            ->assertJsonValidationErrors(['documentNumber', 'documentRevision', 'documentEffectiveDate', 'reviewDate', 'periodStart', 'periodEnd', 'objectId', 'reviewerId', 'accounts', 'preparedBy', 'acknowledgedBy', 'location', 'signedAt']);
        $this->assertDatabaseCount('smki_review_hak_akses', 0);
    }

    public function test_invalid_edits_leave_saved_data_unchanged(): void
    {
        $this->login();
        $payload = $this->complete();
        $id = $this->postJson(self::URL, $payload)->assertCreated()->json('data.id');
        $original = SmkiReviewHakAkses::findOrFail($id)->payload;
        $payload['objectId'] = '999999';
        $payload['reviewerId'] = '999999';
        $payload['periodEnd'] = '2026-09-01';
        $payload['accounts'][0]['userStatus'] = 'Disetujui';
        $this->putJson(self::URL.'/'.$id, $payload)->assertUnprocessable()
            ->assertJsonValidationErrors(['objectId', 'reviewerId', 'periodEnd', 'accounts.0.userStatus']);
        $this->assertSame($original, SmkiReviewHakAkses::findOrFail($id)->payload);
    }

    public function test_duplicate_user_ids_and_malformed_accounts_are_rejected(): void
    {
        $this->login();
        $payload = $this->complete();
        $payload['accounts'][] = array_merge($payload['accounts'][0], ['id' => (string) Str::uuid(), 'username' => ' OPERATOR ']);
        $this->postJson(self::URL, $payload)->assertUnprocessable()->assertJsonValidationErrors('accounts.1.username');
        $payload['accounts'] = ['invalid'];
        $this->postJson(self::URL, $payload)->assertUnprocessable();
    }

    public function test_drafts_still_validate_supplied_dates_and_reference_ids(): void
    {
        $this->login();
        $this->postJson(self::URL, ['status' => 'Draf', 'accounts' => [], 'reviewDate' => '2026-02-30', 'reviewerId' => 999999])
            ->assertUnprocessable()->assertJsonValidationErrors(['reviewDate', 'reviewerId']);
        $this->postJson(self::URL, ['status' => 'Final', 'accounts' => []])->assertUnprocessable()->assertJsonValidationErrors('status');
    }

    public function test_pagination_lookup_and_document_snapshots(): void
    {
        $this->login();
        $payload = $this->complete();
        $first = $this->postJson(self::URL, $payload)->assertCreated()->json('data');
        $second = $this->postJson(self::URL, $payload)->assertCreated()->json('data');
        $this->assertNotSame($first['number'], $second['number']);
        $this->getJson(self::URL.'?per_page=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 2)->assertJsonPath('meta.last_page', 2);
        $this->getJson(self::URL.'/lookup')->assertOk()
            ->assertJsonFragment(['id' => $payload['objectId'], 'name' => 'Sistem FR-034'])
            ->assertJsonFragment(['id' => $payload['reviewerId'], 'name' => 'Pelaksana Uji', 'nip' => '123456789012345678']);
        Pegawai::whereKey($payload['reviewerId'])->update(['nama' => 'Nama berubah']);
        $this->getJson(self::URL.'/'.$first['id'])->assertOk()->assertJsonPath('data.reviewer', 'Pelaksana Uji');
    }

    public function test_inactive_users_and_disabled_smki_cannot_access_reviews(): void
    {
        $user = $this->login('IKP');
        $user->update(['is_active' => 0]);
        $this->getJson(self::URL)->assertForbidden();
        $user->update(['is_active' => 1]);
        $service = DB::table('services')->insertGetId(['code' => 'SMKI', 'name' => 'SMKI', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('bidang_services')->updateOrInsert(['bidang_id' => $user->bidang_id, 'service_id' => $service], ['is_enabled' => false]);
        $this->getJson(self::URL)->assertForbidden();
    }

    public function test_signer_ids_resolve_master_names_and_nips_and_retain_snapshots(): void
    {
        $this->login();
        $payload = $this->complete();
        $payload['preparedById'] = $payload['reviewerId'];
        $payload['acknowledgedById'] = $payload['reviewerId'];
        $payload['preparedByNip'] = 'NIP palsu';
        $payload['acknowledgedByNip'] = 'NIP palsu';
        $id = $this->postJson(self::URL, $payload)->assertCreated()
            ->assertJsonPath('data.preparedById', $payload['reviewerId'])
            ->assertJsonPath('data.acknowledgedById', $payload['reviewerId'])
            ->assertJsonPath('data.preparedBy', 'Pelaksana Uji')
            ->assertJsonPath('data.acknowledgedBy', 'Pelaksana Uji')
            ->assertJsonPath('data.preparedByNip', '123456789012345678')
            ->assertJsonPath('data.acknowledgedByNip', '123456789012345678')->json('data.id');
        Pegawai::whereKey($payload['reviewerId'])->update(['nama' => 'Nama baru', 'nip' => '999999999999999999']);
        $this->getJson(self::URL.'/'.$id)->assertOk()
            ->assertJsonPath('data.preparedBy', 'Pelaksana Uji')
            ->assertJsonPath('data.preparedByNip', '123456789012345678');

        $payload['preparedById'] = '';
        $payload['acknowledgedById'] = '';
        $payload['preparedByNip'] = '111111111111111111';
        $payload['acknowledgedByNip'] = '222222222222222222';
        $this->putJson(self::URL.'/'.$id, $payload)->assertOk()
            ->assertJsonPath('data.preparedById', '')
            ->assertJsonPath('data.preparedBy', 'Pembuat Uji')
            ->assertJsonPath('data.preparedByNip', '111111111111111111')
            ->assertJsonPath('data.acknowledgedByNip', '222222222222222222');
    }

    public function test_drafts_validate_and_persist_signer_references(): void
    {
        $this->login();
        $payload = $this->complete();
        $this->postJson(self::URL, ['status' => 'Draf', 'accounts' => [], 'preparedById' => $payload['reviewerId']])
            ->assertCreated()->assertJsonPath('data.preparedBy', 'Pelaksana Uji')
            ->assertJsonPath('data.preparedByNip', '123456789012345678');
        $this->postJson(self::URL, ['status' => 'Draf', 'accounts' => [], 'preparedById' => '999999', 'acknowledgedById' => '999999'])
            ->assertUnprocessable()->assertJsonValidationErrors(['preparedById', 'acknowledgedById']);
    }

    public function test_specimen_qr_can_be_disabled_without_changing_the_default(): void
    {
        $this->login();
        $path = tempnam(sys_get_temp_dir(), 'fr034-specimen-');
        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aYJkAAAAASUVORK5CYII='));
        try {
            $this->mock(\App\Services\TteResolverService::class, function ($mock) use ($path) {
                $mock->shouldReceive('imagePath')->once()->with('123', 'Uji', true)->andReturn($path);
                $mock->shouldReceive('imagePath')->once()->with('123', 'Uji', false)->andReturn($path);
            });
            $this->get('/api/tte/resolve/image?nip=123&nama=Uji')->assertOk()->assertHeader('Content-Type', 'image/png');
            $this->get('/api/tte/resolve/image?nip=123&nama=Uji&include_qr=0')->assertOk()->assertHeader('Content-Type', 'image/png');
            $this->getJson('/api/tte/resolve/image?nama=Uji&include_qr=invalid')->assertUnprocessable()->assertJsonValidationErrors('include_qr');
        } finally {
            unlink($path);
        }
    }
}
