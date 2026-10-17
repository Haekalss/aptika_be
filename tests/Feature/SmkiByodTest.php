<?php

namespace Tests\Feature;

use App\Models\Bidang;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SmkiByodTest extends TestCase
{
    use RefreshDatabase;

    private function actingBidangUser(string $code = 'TESTBID'): User
    {
        $bidang = Bidang::create(['code' => $code, 'name' => 'Bidang ' . $code]);
        $user = User::factory()->create(['is_active' => 1, 'bidang_id' => $bidang->id]);
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    private function payload(array $override = []): array
    {
        return array_merge([
            'nama'              => 'Budi Santoso',
            'nip'               => '198001012010011001',
            'jabatan'           => 'Kepala Bidang Aptika',
            'unit_kerja'        => 'Bidang Aptika',
            'ditandatangani_di' => 'Bandung',
            'tanggal_ttd'       => '2026-10-17',
            'perangkat' => [
                ['nama' => 'Dina Pratiwi', 'nip' => '199202022018021001', 'jenis_perangkat' => 'Laptop', 'merek_tipe' => 'Lenovo E14', 'nomor_seri' => 'PF3ABC12'],
                ['nama' => 'Eko', 'nip' => null, 'jenis_perangkat' => 'Tab', 'merek_tipe' => 'iPad 9', 'nomor_seri' => 'DMPXYZ99'],
            ],
        ], $override);
    }

    public function test_crud_byod_works(): void
    {
        $this->actingBidangUser();

        $create = $this->postJson('/api/smki/perangkat-pribadi', $this->payload());
        $create->assertStatus(201)->assertJsonPath('data.unit_kerja', 'Bidang Aptika');
        $id = $create->json('data.id');
        $this->assertCount(2, $create->json('data.perangkat'));

        $this->getJson('/api/smki/perangkat-pribadi')
            ->assertOk()
            ->assertJsonPath('stats.total_formulir', 1)
            ->assertJsonPath('stats.total_perangkat', 2)
            ->assertJsonPath('stats.laptop', 1)
            ->assertJsonPath('stats.tab', 1)
            ->assertJsonPath('data.0.total_perangkat', 2);

        $this->getJson('/api/smki/perangkat-pribadi?search=DMPXYZ99')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/smki/perangkat-pribadi?jenis_perangkat=PC')->assertOk()->assertJsonPath('meta.total', 0);

        $this->getJson("/api/smki/perangkat-pribadi/{$id}")->assertOk()->assertJsonCount(2, 'data.perangkat');

        $update = $this->putJson("/api/smki/perangkat-pribadi/{$id}", $this->payload([
            'perangkat' => [
                ['nama' => 'Fajar', 'jenis_perangkat' => 'PC', 'merek_tipe' => 'Dell Optiplex', 'nomor_seri' => 'SN-777'],
            ],
        ]));
        $update->assertOk();
        $this->assertCount(1, $update->json('data.perangkat'));

        $this->deleteJson("/api/smki/perangkat-pribadi/{$id}")->assertOk();
        $this->getJson("/api/smki/perangkat-pribadi/{$id}")->assertStatus(404);
    }

    public function test_validation_errors(): void
    {
        $this->actingBidangUser();

        $this->postJson('/api/smki/perangkat-pribadi', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['nama', 'jabatan', 'unit_kerja', 'perangkat']);

        $bad = $this->payload();
        $bad['perangkat'][0]['jenis_perangkat'] = 'Handphone';
        $this->postJson('/api/smki/perangkat-pribadi', $bad)
            ->assertStatus(422)->assertJsonValidationErrors(['perangkat.0.jenis_perangkat']);
    }

    public function test_data_isolated_per_bidang(): void
    {
        $this->actingBidangUser('A');
        $id = $this->postJson('/api/smki/perangkat-pribadi', $this->payload())->json('data.id');

        $this->actingBidangUser('B');
        $this->getJson('/api/smki/perangkat-pribadi')->assertOk()->assertJsonPath('stats.total_formulir', 0);
        $this->getJson("/api/smki/perangkat-pribadi/{$id}")->assertStatus(404);
    }
}
