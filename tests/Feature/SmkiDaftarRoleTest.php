<?php

namespace Tests\Feature;

use App\Models\Bidang;
use App\Models\Pegawai;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SmkiDaftarRoleTest extends TestCase
{
    use RefreshDatabase;

    private function actingBidangUser(string $code = 'UAM'): User
    {
        $bidang = Bidang::create(['code' => $code, 'name' => 'Bidang ' . $code]);
        $user = User::factory()->create([
            'role' => 'user',
            'is_active' => 1,
            'bidang_id' => $bidang->id,
        ]);
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    private function pegawai(string $nama, string $nip): Pegawai
    {
        return Pegawai::create([
            'nama' => $nama,
            'nip' => $nip,
            'pangkat' => 'IV/a',
            'jabatan' => 'Staf',
            'role' => 'staff',
            'tanggal_lahir' => '1980-01-01',
        ]);
    }

    private function payload(array $override = []): array
    {
        return array_merge([
            'status' => 'Draf',
            'documentNumber' => 'FR-033/KOM.03.05/SANDIKAMI',
            'revision' => '0.0',
            'effectiveDate' => '2022-08-19',
            'unit' => 'Bidang Aplikasi Informatika',
            'roles' => [
                [
                    'id' => '11111111-1111-4111-8111-111111111111',
                    'role' => 'Administrator',
                    'access' => 'Melakukan CRUD atau pengaksesan ke semua file/program di dalam sistem.',
                ],
            ],
            'note' => 'Role dapat ditambahkan sesuai dengan kondisi masing-masing Unit Kerja.',
            'city' => 'Bandung',
            'documentDate' => '2026-11-20',
        ], $override);
    }

    public function test_lookup_returns_masters(): void
    {
        $this->actingBidangUser();
        $this->pegawai('Pegawai Uji', '198001012006011001');

        $this->getJson('/api/smki/daftar-role/lookup')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data.employees')
            ->assertJsonFragment(['code' => 'UAM']);
    }

    public function test_crud_daftar_role_works(): void
    {
        $this->actingBidangUser();
        $prepared = $this->pegawai('Pembuat Uji', '198001012006011001');
        $approved = $this->pegawai('Pejabat Uji', '198001012006011002');

        $create = $this->postJson('/api/smki/daftar-role', $this->payload());
        $create->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'Draf')
            ->assertJsonPath('data.number', 'FRM-033-' . now()->year . '-000001')
            ->assertJsonCount(1, 'data.roles');
        $id = $create->json('data.id');

        $this->getJson('/api/smki/daftar-role')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('stats.total', 1)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.last_page', 1);

        $this->getJson('/api/smki/daftar-role?search=Administrator&status=Draf')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->getJson("/api/smki/daftar-role/{$id}")
            ->assertOk()
            ->assertJsonPath('data.unit', 'Bidang Aplikasi Informatika')
            ->assertJsonPath('data.permissions.can_update', true)
            ->assertJsonPath('data.permissions.can_delete', true);

        $update = $this->putJson("/api/smki/daftar-role/{$id}", $this->payload([
            'status' => 'Tersimpan',
            'preparedById' => $prepared->id,
            'approvedById' => $approved->id,
            'roles' => [
                [
                    'id' => '11111111-1111-4111-8111-111111111111',
                    'role' => 'Administrator',
                    'access' => 'Akses penuh ke sistem.',
                ],
                [
                    'id' => '22222222-2222-4222-8222-222222222222',
                    'role' => 'Operator',
                    'access' => 'Akses terbatas pada modul tertentu.',
                ],
            ],
        ]));
        $update->assertOk()
            ->assertJsonPath('data.status', 'Tersimpan')
            ->assertJsonPath('data.preparedBy', 'Pembuat Uji')
            ->assertJsonPath('data.preparedByNip', '198001012006011001')
            ->assertJsonPath('data.approvedBy', 'Pejabat Uji')
            ->assertJsonCount(2, 'data.roles');

        $this->deleteJson("/api/smki/daftar-role/{$id}")->assertOk();
        $this->getJson("/api/smki/daftar-role/{$id}")->assertStatus(404);
    }

    public function test_export_docx_works(): void
    {
        $this->actingBidangUser();
        $created = $this->postJson('/api/smki/daftar-role', $this->payload());
        $id = $created->json('data.id');

        $response = $this->get("/api/smki/daftar-role/{$id}/export-docx");
        $response->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document')
            ->assertHeader('content-disposition', 'attachment; filename=FR-033_Daftar-Role_'.$id.'.docx');
    }

    public function test_validation_for_final_form(): void
    {
        $this->actingBidangUser();

        $this->postJson('/api/smki/daftar-role', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);

        $this->postJson('/api/smki/daftar-role', $this->payload([
            'status' => 'Tersimpan',
            'documentNumber' => '',
            'unit' => '',
            'roles' => [],
        ]))->assertStatus(422)->assertJsonValidationErrors([
            'documentNumber', 'unit', 'roles', 'preparedById', 'approvedById',
        ]);
    }

    public function test_client_id_is_idempotent(): void
    {
        $this->actingBidangUser();
        $clientId = '33333333-3333-4333-8333-333333333333';

        $first = $this->postJson('/api/smki/daftar-role', $this->payload([
            'clientId' => $clientId,
            'unit' => 'Pertama',
        ]));
        $first->assertStatus(201);
        $id = $first->json('data.id');

        $second = $this->postJson('/api/smki/daftar-role', $this->payload([
            'clientId' => $clientId,
            'unit' => 'Diubah',
        ]));
        $second->assertOk()
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.unit', 'Pertama');

        $this->getJson('/api/smki/daftar-role')->assertJsonCount(1, 'data');
    }

    public function test_user_only_sees_own_bidang(): void
    {
        $this->actingBidangUser('BIDANG A');
        $own = $this->postJson('/api/smki/daftar-role', $this->payload(['unit' => 'Milik Sendiri']));
        $own->assertStatus(201);

        $this->actingBidangUser('BIDANG B');
        $this->getJson('/api/smki/daftar-role')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/smki/daftar-role/' . $own->json('data.id'))->assertStatus(404);
    }
}
