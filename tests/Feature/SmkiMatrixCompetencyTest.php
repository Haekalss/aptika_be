<?php

namespace Tests\Feature;

use App\Models\Bidang;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SmkiMatrixCompetencyTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        $bidang = Bidang::create(['code' => 'APTIKA', 'name' => 'Bidang APTIKA']);
        $user = User::factory()->create(['is_active' => 1, 'bidang_id' => $bidang->id]);
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    private function payload(array $over = []): array
    {
        return array_merge([
            'nama_unit_kerja' => 'Bidang APTIKA',
            'competencies' => ['K-01', 'K-02', 'K-03'],
            'roles' => [
                ['unit_label' => 'Bidang APTIKA', 'peran' => 'Kordinator', 'levels' => [4, 4, 4]],
                ['unit_label' => 'Bidang APTIKA', 'peran' => 'Team Teknis', 'levels' => [2, 2, 2]],
            ],
            'employees' => [
                ['nama' => 'Budi', 'nip' => '123', 'jabatan' => 'Pranata Komputer', 'peran' => 'Team Teknis', 'levels' => [3, 1, 2], 'usulan_training' => 'Pelatihan Jaringan'],
            ],
            'trainings' => [
                ['nama' => 'Budi', 'jabatan' => 'Pranata Komputer', 'usulan_training' => 'Pelatihan Jaringan'],
            ],
        ], $over);
    }

    public function test_crud_matrix_competency_with_gap(): void
    {
        $this->user();

        $create = $this->postJson('/api/smki/matrix-competency', $this->payload());
        $create->assertCreated()->assertJsonPath('data.nama_unit_kerja', 'Bidang APTIKA');
        $id = $create->json('data.id');
        // gap pegawai Team Teknis vs standar [2,2,2] => [1,-1,0]
        $create->assertJsonPath('data.employees.0.gap', [1, -1, 0]);

        $this->getJson('/api/smki/matrix-competency?search=FR-031')
            ->assertOk()->assertJsonPath('meta.total', 1);

        $this->getJson('/api/smki/matrix-competency/lookup')
            ->assertOk()->assertJsonPath('data.default_competencies.0', 'K-01');

        $update = $this->putJson("/api/smki/matrix-competency/{$id}", $this->payload([
            'nama_unit_kerja' => 'Bidang Persandian',
            'employees' => [
                ['nama' => 'Sari', 'jabatan' => 'Analis', 'peran' => 'Kordinator', 'levels' => [4, 3, 4]],
            ],
        ]));
        $update->assertOk()->assertJsonPath('data.nama_unit_kerja', 'Bidang Persandian');
        $update->assertJsonPath('data.employees.0.gap', [0, -1, 0]);

        $this->deleteJson("/api/smki/matrix-competency/{$id}")->assertOk();
        $this->getJson("/api/smki/matrix-competency/{$id}")->assertNotFound();
    }

    public function test_validation_rejects_bad_levels_and_empty_competencies(): void
    {
        $this->user();
        $this->postJson('/api/smki/matrix-competency', $this->payload(['competencies' => []]))
            ->assertUnprocessable()->assertJsonValidationErrors(['competencies']);
        $bad = $this->payload();
        $bad['roles'][0]['levels'] = [5, 0, 0];
        $this->postJson('/api/smki/matrix-competency', $bad)
            ->assertUnprocessable()->assertJsonValidationErrors(['roles.0.levels.0']);
    }
}
