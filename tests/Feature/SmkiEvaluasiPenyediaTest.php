<?php

namespace Tests\Feature;

use App\Models\Bidang;
use App\Models\SmkiEvaluasiKriteria;
use App\Models\SmkiSkalaNilai;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SmkiEvaluasiPenyediaTest extends TestCase
{
    use RefreshDatabase;

    private User $creator;
    private User $admin;
    private User $outsider;

    protected function setUp(): void
    {
        parent::setUp();
        $bidang = Bidang::create(['code' => 'TESTBID', 'name' => 'Bidang Test']);
        $make = fn (string $name, string $role) => User::factory()->create([
            'name' => $name, 'is_active' => 1, 'bidang_id' => $bidang->id, 'role' => $role,
        ]);
        $this->creator = $make('Creator', 'user');
        $this->admin = $make('Admin Atasan', 'admin');
        $this->outsider = $make('Outsider', 'user');
    }

    private function identity(array $overrides = []): array
    {
        return array_merge([
            'penyedia_id' => null,
            'penyedia_nama' => 'PT Test FR021',
            'contact_person' => 'Kontak Test',
            'email' => 'kontak@test.local',
            'no_kontrak' => 'KTR-TEST-001',
            'nomor_dokumen' => 'FR-021/Test',
            'nomor_revisi' => '1.1',
            'tanggal_berlaku' => '2026-11-02',
            'sifat_pekerjaan' => 'pengadaan_baru',
            'sifat_pekerjaan_lainnya' => '',
            'tanggal_evaluasi' => '2026-11-02',
            'tempat' => 'Bandung',
        ], $overrides);
    }

    private function createDraft(User $user, array $overrides = []): array
    {
        $res = $this->actingAs($user, 'sanctum')
            ->postJson('/api/smki/evaluasi-penyedia', $this->identity($overrides));
        $res->assertStatus(201);
        return [$res->json('data.id'), $res->json('data.version')];
    }

    private function penilaianPayload(array $skalaIds): array
    {
        $criteria = SmkiEvaluasiKriteria::ordered()->pluck('id')->all();
        return ['penilaian' => array_map(
            fn ($id, $i) => ['kriteria_id' => $id, 'skala_id' => $skalaIds[$i]],
            $criteria,
            array_keys($criteria)
        )];
    }

    private function skalaId(string $label): int
    {
        return SmkiSkalaNilai::where('label', $label)->value('id');
    }

    private function scoreAndCalculate(int $id, int $version, User $user, array $skalaIds): int
    {
        $payload = $this->penilaianPayload($skalaIds);
        $payload['version'] = $version;
        $save = $this->actingAs($user, 'sanctum')
            ->putJson("/api/smki/evaluasi-penyedia/{$id}/penilaian", $payload);
        $save->assertOk();
        $hit = $this->actingAs($user, 'sanctum')
            ->postJson("/api/smki/evaluasi-penyedia/{$id}/hitung", ['version' => $save->json('data.version')]);
        $hit->assertOk();
        return $hit->json('data.version');
    }

    public function test_master_and_admin_list(): void
    {
        $this->actingAs($this->creator, 'sanctum')
            ->getJson('/api/smki/evaluasi-penyedia/master')
            ->assertOk()
            ->assertJsonCount(4, 'data.kriteria')
            ->assertJsonCount(3, 'data.skala')
            ->assertJsonCount(3, 'data.rekomendasi')
            ->assertJsonPath('data.template.nomor_dokumen', 'FR-021/KOM.03.05/SANDIKAMI');

        // Admin pemanggil tetap muncul di daftar (boleh memilih diri sendiri).
        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/smki/evaluasi-penyedia/admins')
            ->assertOk()
            ->assertJsonFragment(['name' => 'Admin Atasan']);

        // Endpoint evaluators sudah dihapus (Evaluator = perwakilan vendor).
        $this->actingAs($this->creator, 'sanctum')
            ->getJson('/api/smki/evaluasi-penyedia/evaluators')
            ->assertNotFound();
    }

    public function test_calculation_boundaries(): void
    {
        $baik = $this->skalaId('Baik');
        $sedang = $this->skalaId('Sedang');
        $kurang = $this->skalaId('Kurang');
        $cases = [
            [[$baik, $baik, $baik, $baik], 400, 'Memuaskan'],
            [[$baik, $baik, $baik, $sedang], 360, 'Cukup memuaskan'],
            [[$sedang, $sedang, $sedang, $sedang], 240, 'Kurang memuaskan'],
            [[$kurang, $kurang, $kurang, $kurang], 40, 'Kurang memuaskan'],
        ];
        foreach ($cases as [$skalaIds, $total, $kategori]) {
            [$id, $version] = $this->createDraft($this->creator, ['no_kontrak' => 'KTR-' . $total . '-' . fake()->unique()->randomNumber()]);
            $this->scoreAndCalculate($id, $version, $this->creator, $skalaIds);
            $this->actingAs($this->creator, 'sanctum')
                ->getJson("/api/smki/evaluasi-penyedia/{$id}")
                ->assertOk()
                ->assertJsonPath('data.total_nilai', $total)
                ->assertJsonPath('data.rekomendasi.kategori', $kategori);
        }
    }

    public function test_hitung_requires_complete_scores(): void
    {
        [$id, $version] = $this->createDraft($this->creator);
        $this->actingAs($this->creator, 'sanctum')
            ->postJson("/api/smki/evaluasi-penyedia/{$id}/hitung", ['version' => $version])
            ->assertStatus(422)
            ->assertJsonValidationErrors('penilaian');
    }

    public function test_vendor_evaluator_ajukan_flow(): void
    {
        [$id, $version] = $this->createDraft($this->creator);
        $version = $this->scoreAndCalculate($id, $version, $this->creator, array_fill(0, 4, $this->skalaId('Baik')));

        // Pembuat mengajukan langsung; TTE Evaluator tercatat dari vendor.
        $aj = $this->actingAs($this->creator, 'sanctum')
            ->postJson("/api/smki/evaluasi-penyedia/{$id}/ajukan", [
                'admin_id' => $this->admin->id, 'version' => $version,
            ]);
        $aj->assertOk()->assertJsonPath('data.status', 'diajukan');
        $this->assertEquals('Kontak Test', $aj->json('data.ttd_evaluator_nama'));
        $this->assertEquals('Perwakilan Penyedia Jasa', $aj->json('data.ttd_evaluator_jabatan'));
        $this->assertNull($aj->json('data.ttd_evaluator_nip'));
        $version = $aj->json('data.version');

        // Admin yang ditugaskan menyetujui.
        $set = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/smki/evaluasi-penyedia/{$id}/setujui", ['version' => $version]);
        $set->assertOk()->assertJsonPath('data.status', 'selesai');

        // Riwayat + snapshot penilaian.
        $riwayat = $this->actingAs($this->creator, 'sanctum')
            ->getJson("/api/smki/evaluasi-penyedia/{$id}/riwayat")
            ->assertOk();
        $log = collect($riwayat->json('data'))->firstWhere('aksi', 'diajukan');
        $this->assertNotNull($log);
        $this->actingAs($this->creator, 'sanctum')
            ->getJson("/api/smki/evaluasi-penyedia/{$id}/riwayat/{$log['id']}/penilaian")
            ->assertOk()
            ->assertJsonCount(4, 'data.penilaian');
    }

    public function test_return_revise_resubmit_cycle(): void
    {
        [$id, $version] = $this->createDraft($this->creator);
        $version = $this->scoreAndCalculate($id, $version, $this->creator, array_fill(0, 4, $this->skalaId('Baik')));
        $aj = $this->actingAs($this->creator, 'sanctum')
            ->postJson("/api/smki/evaluasi-penyedia/{$id}/ajukan", [
                'admin_id' => $this->admin->id, 'version' => $version,
            ]);
        $aj->assertOk();
        $version = $aj->json('data.version');

        $back = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/smki/evaluasi-penyedia/{$id}/kembalikan", [
                'catatan' => 'Tinjau kembali kriteria pertama.', 'version' => $version,
            ]);
        $back->assertOk()->assertJsonPath('data.status', 'revisi');
        $version = $back->json('data.version');

        $sedang = $this->skalaId('Sedang');
        $baik = $this->skalaId('Baik');
        $version = $this->scoreAndCalculate($id, $version, $this->creator, [$sedang, $baik, $baik, $baik]);
        $re = $this->actingAs($this->creator, 'sanctum')
            ->postJson("/api/smki/evaluasi-penyedia/{$id}/ajukan", [
                'admin_id' => $this->admin->id, 'version' => $version,
            ]);
        $re->assertOk()
            ->assertJsonPath('data.siklus', 2)
            ->assertJsonPath('data.total_nilai', 360);
    }

    public function test_authorization_and_locking(): void
    {
        [$id, $version] = $this->createDraft($this->creator);

        // Orang lain tidak melihat record (404, bukan 403).
        $this->actingAs($this->outsider, 'sanctum')
            ->getJson("/api/smki/evaluasi-penyedia/{$id}")
            ->assertNotFound();
        $this->actingAs($this->outsider, 'sanctum')
            ->getJson('/api/smki/evaluasi-penyedia')
            ->assertOk()
            ->assertJsonPath('summary.total', 0);

        // Version basi → 409.
        $this->actingAs($this->creator, 'sanctum')
            ->putJson("/api/smki/evaluasi-penyedia/{$id}/identifikasi", array_merge($this->identity(), ['version' => $version + 99]))
            ->assertStatus(409);

        // Hapus oleh bukan pemilik → 404; hapus draft oleh pemilik → 204.
        $this->actingAs($this->outsider, 'sanctum')
            ->deleteJson("/api/smki/evaluasi-penyedia/{$id}", ['version' => $version])
            ->assertStatus(404);
        $this->actingAs($this->creator, 'sanctum')
            ->deleteJson("/api/smki/evaluasi-penyedia/{$id}", ['version' => $version])
            ->assertNoContent();
    }

    public function test_identification_validation(): void
    {
        // Tanpa penyedia → 422 field penyedia_id.
        $this->actingAs($this->creator, 'sanctum')
            ->postJson('/api/smki/evaluasi-penyedia', $this->identity(['penyedia_id' => null, 'penyedia_nama' => '']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('penyedia_id');

        // Email invalid → 422.
        $this->actingAs($this->creator, 'sanctum')
            ->postJson('/api/smki/evaluasi-penyedia', $this->identity(['email' => 'bukan-email']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        // Atasan bukan admin → 422 saat ajukan.
        [$id, $version] = $this->createDraft($this->creator);
        $version = $this->scoreAndCalculate($id, $version, $this->creator, array_fill(0, 4, $this->skalaId('Baik')));
        $this->actingAs($this->creator, 'sanctum')
            ->postJson("/api/smki/evaluasi-penyedia/{$id}/ajukan", [
                'admin_id' => $this->creator->id, 'version' => $version,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('admin_id');
    }

    public function test_penyedia_lookup_and_create(): void
    {
        $this->actingAs($this->creator, 'sanctum')
            ->postJson('/api/smki/penyedia', ['nama_penyedia' => 'PT Lookup Test'])
            ->assertStatus(201);
        $this->actingAs($this->creator, 'sanctum')
            ->getJson('/api/smki/penyedia?q=Lookup')
            ->assertOk()
            ->assertJsonFragment(['nama_penyedia' => 'PT Lookup Test']);
        $this->actingAs($this->creator, 'sanctum')
            ->postJson('/api/smki/penyedia', ['nama_penyedia' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors('nama_penyedia');
    }
}
