<?php

namespace Tests\Feature\Smki;

use App\Models\Bidang;
use App\Models\DaftarAsetTi;
use App\Models\MasterSistemAplikasi;
use App\Models\Pegawai;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LogPerubahanAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== ':memory:') {
            throw new \RuntimeException('Tes FR-032 hanya boleh memakai SQLite :memory:. Database lain tidak akan diubah.');
        }
    }

    protected function migrateFreshUsing(): array
    {
        // Load the real schemas used by FR-032. Unrelated legacy migrations
        // contain MySQL-only ALTER syntax and cannot run on SQLite.
        return ['--path' => [
            'database/migrations/0001_01_01_000000_create_users_table.php',
            'database/migrations/2026_07_03_075728_create_pegawai_table.php',
            'database/migrations/2026_08_20_000001_create_bidangs_table.php',
            'database/migrations/2026_08_20_000002_create_services_table.php',
            'database/migrations/2026_08_20_000003_create_bidang_services_table.php',
            'database/migrations/2026_08_20_000004_add_bidang_id_to_users_table.php',
            'database/migrations/2026_08_20_000006_add_parent_id_to_services_table.php',
            'database/migrations/2026_09_11_000001_create_daftar_aset_ti_tables.php',
            'database/migrations/2026_10_03_000001_create_hak_akses_ti_tables.php',
            'database/migrations/2026_10_18_000001_create_smki_log_perubahan_table.php',
        ]];
    }

    public function test_users_and_admins_share_drafts_only_within_their_bidang(): void
    {
        $aptika = Bidang::where('code', 'APTIKA')->firstOrFail();
        $ikp = Bidang::where('code', 'IKP')->firstOrFail();
        $writer = User::factory()->create(['bidang_id' => $aptika->id, 'role' => 'user', 'is_active' => 1]);
        $colleague = User::factory()->create(['bidang_id' => $aptika->id, 'role' => 'user', 'is_active' => 1]);
        $admin = User::factory()->create(['bidang_id' => $aptika->id, 'role' => 'admin', 'is_active' => 1]);
        $outsider = User::factory()->create(['bidang_id' => $ikp->id, 'role' => 'admin', 'is_active' => 1]);
        $system = MasterSistemAplikasi::create(['nama_sistem' => 'Sistem Uji']);

        $this->actingAs($writer, 'sanctum');
        $created = $this->postJson('/api/smki/log-perubahan', [
            'title' => 'Perubahan bersama',
            'references' => ['objectKind' => 'sistem', 'objectId' => $system->getKey(), 'executorId' => null],
        ])->assertCreated()->assertJsonPath('data.status', 'Draf');
        $id = $created->json('data.id');
        $this->assertSame($writer->id, $created->json('data.references.recorderId'));

        $this->actingAs($colleague, 'sanctum');
        $this->getJson('/api/smki/log-perubahan')->assertOk()->assertJsonCount(1, 'data');
        $this->putJson("/api/smki/log-perubahan/{$id}", [
            'title' => 'Diperbarui rekan',
            'references' => ['objectKind' => 'sistem', 'objectId' => $system->getKey(), 'executorId' => null],
        ])->assertOk()->assertJsonPath('data.references.recorderId', $writer->id);

        $this->actingAs($admin, 'sanctum');
        $this->getJson("/api/smki/log-perubahan/{$id}")->assertOk()
            ->assertJsonPath('data.title', 'Diperbarui rekan');

        $this->actingAs($outsider, 'sanctum');
        $this->getJson('/api/smki/log-perubahan')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/smki/log-perubahan/{$id}")->assertNotFound();
        $this->putJson("/api/smki/log-perubahan/{$id}", ['title' => 'Terlarang'])->assertNotFound();
        $this->postJson("/api/smki/log-perubahan/{$id}/finalisasi")->assertNotFound();
        $this->deleteJson("/api/smki/log-perubahan/{$id}")->assertNotFound();
        $otherId = $this->postJson('/api/smki/log-perubahan', [
            'title' => 'Log IKP',
            'references' => ['objectKind' => 'sistem', 'objectId' => $system->getKey(), 'executorId' => null],
        ])->assertCreated()->json('data.id');

        $this->actingAs($admin, 'sanctum');
        $this->getJson('/api/smki/log-perubahan')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson("/api/smki/log-perubahan/{$otherId}")->assertOk();
        $this->putJson("/api/smki/log-perubahan/{$otherId}", [
            'title' => 'Ditinjau Admin Aptika',
            'references' => ['bidangId' => $ikp->id, 'objectKind' => 'sistem', 'objectId' => $system->getKey(), 'executorId' => null],
        ])->assertOk()->assertJsonPath('data.references.bidangId', $ikp->id)
            ->assertJsonPath('data.references.recorderId', $outsider->id);
        $this->deleteJson("/api/smki/log-perubahan/{$id}")->assertOk();
        $this->deleteJson("/api/smki/log-perubahan/{$otherId}")->assertOk();
    }

    public function test_only_admin_aptika_can_select_another_bidang(): void
    {
        $aptika = Bidang::where('code', 'APTIKA')->firstOrFail();
        $ikp = Bidang::where('code', 'IKP')->firstOrFail();
        $system = MasterSistemAplikasi::create(['nama_sistem' => 'Sistem Uji']);
        $asset = DaftarAsetTi::create(['bidang_id' => $ikp->id, 'nama_aset' => 'Server IKP', 'kode' => 'IKP-01']);
        foreach (['admin', 'user'] as $role) {
            $ordinary = User::factory()->create(['bidang_id' => $ikp->id, 'role' => $role, 'is_active' => 1]);
            $this->actingAs($ordinary, 'sanctum');
            $this->getJson('/api/smki/log-perubahan/lookup?bidang_id='.$aptika->id)->assertForbidden();
            $this->postJson('/api/smki/log-perubahan', [
                'title' => 'Bidang terlarang',
                'references' => ['bidangId' => $aptika->id, 'objectKind' => 'sistem', 'objectId' => $system->getKey()],
            ])->assertForbidden();
        }
        $staff = User::factory()->create(['bidang_id' => $aptika->id, 'role' => 'user', 'is_active' => 1]);
        $this->actingAs($staff, 'sanctum');
        $this->getJson('/api/smki/log-perubahan/lookup?bidang_id='.$ikp->id)->assertForbidden();
        $this->postJson('/api/smki/log-perubahan', [
            'title' => 'Aset bidang lain',
            'references' => ['bidangId' => $aptika->id, 'objectKind' => 'aset', 'objectId' => $asset->id],
        ])->assertUnprocessable();

        $admin = User::factory()->create(['bidang_id' => $aptika->id, 'role' => 'admin', 'is_active' => 1]);
        $this->actingAs($admin, 'sanctum');
        $this->getJson('/api/smki/log-perubahan/lookup?bidang_id='.$ikp->id)->assertOk()
            ->assertJsonPath('data.bidang.id', $ikp->id)->assertJsonPath('data.canAccessAllBidang', true);
        $created = $this->postJson('/api/smki/log-perubahan', [
            'title' => 'Dibuat untuk IKP',
            'references' => ['bidangId' => $ikp->id, 'objectKind' => 'aset', 'objectId' => $asset->id],
        ])->assertCreated()->assertJsonPath('data.references.bidangId', $ikp->id);
        $this->actingAs($staff, 'sanctum');
        $this->getJson('/api/smki/log-perubahan/'.$created->json('data.id'))->assertNotFound();
    }

    public function test_final_log_is_locked_and_requires_a_master_employee(): void
    {
        $bidang = Bidang::where('code', 'APTIKA')->firstOrFail();
        $user = User::factory()->create(['bidang_id' => $bidang->id, 'role' => 'user', 'is_active' => 1]);
        $system = MasterSistemAplikasi::create(['nama_sistem' => 'Sistem Uji']);
        $employee = Pegawai::create([
            'nama' => 'Pelaksana Uji', 'nip' => '199901012020121001',
            'pangkat' => 'Penata', 'jabatan' => 'Pranata Komputer',
            'role' => 'staff', 'tanggal_lahir' => '1999-01-01',
        ]);
        $this->actingAs($user, 'sanctum');
        $created = $this->postJson('/api/smki/log-perubahan', [
            'title' => 'Perubahan final',
            'references' => ['objectKind' => 'sistem', 'objectId' => $system->getKey(), 'executorId' => null],
        ])->assertCreated();
        $id = $created->json('data.id');
        $this->postJson("/api/smki/log-perubahan/{$id}/finalisasi")->assertUnprocessable();
        $this->putJson("/api/smki/log-perubahan/{$id}", [
            'title' => 'Perubahan final', 'startedAt' => '2026-10-18T09:00',
            'endedAt' => '2026-10-18T10:00', 'before' => 'Sebelum',
            'action' => 'Tindakan', 'after' => 'Sesudah', 'testing' => 'Lulus',
            'outcome' => 'Berhasil',
            'references' => ['objectKind' => 'sistem', 'objectId' => $system->getKey(), 'executorId' => $employee->id],
        ])->assertOk();
        $this->postJson("/api/smki/log-perubahan/{$id}/finalisasi")->assertOk()
            ->assertJsonPath('data.status', 'Final');
        $this->putJson("/api/smki/log-perubahan/{$id}", ['title' => 'Ubah lagi'])->assertStatus(409);
        $this->deleteJson("/api/smki/log-perubahan/{$id}")->assertStatus(409);
    }
}
