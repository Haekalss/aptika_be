<?php

namespace Tests\Feature;

use App\Models\Bidang;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SmkiReviewLogTest extends TestCase
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
            'tanggal_review'   => '2026-10-01',
            'periode_mulai'    => '2026-09-01',
            'periode_selesai'  => '2026-09-30',
            'obyek_review'     => 'Server Aplikasi SIMPEG',
            'pelaksana_review' => 'Tim Keamanan Informasi',
            'tindak_lanjut'    => 'Menonaktifkan akun yang tidak dikenal.',
            'ditandatangani_di' => 'Bandung',
            'tanggal_ttd'      => '2026-10-02',
            'items' => [
                ['user_log' => 'admin01', 'ip_address' => '10.10.1.5', 'tanggal' => '2026-09-10', 'waktu' => '08:15', 'durasi' => '45 menit'],
                ['user_log' => 'dbadmin', 'ip_address' => '10.10.1.9', 'tanggal' => '2026-09-12', 'waktu' => '22:40', 'durasi' => '2 jam'],
            ],
        ], $override);
    }

    public function test_crud_review_log_works(): void
    {
        $this->actingBidangUser();

        $create = $this->postJson('/api/smki/review-log', $this->payload());
        $create->assertStatus(201)->assertJsonPath('data.obyek_review', 'Server Aplikasi SIMPEG');
        $id = $create->json('data.id');
        $this->assertCount(2, $create->json('data.items'));

        $this->getJson('/api/smki/review-log')
            ->assertOk()
            ->assertJsonPath('stats.total_review', 1)
            ->assertJsonPath('stats.total_log', 2)
            ->assertJsonPath('data.0.total_log', 2);

        $this->getJson('/api/smki/review-log?search=dbadmin')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/smki/review-log?search=tidak-ada')->assertOk()->assertJsonPath('meta.total', 0);

        $this->getJson("/api/smki/review-log/{$id}")->assertOk()->assertJsonCount(2, 'data.items');

        $update = $this->putJson("/api/smki/review-log/{$id}", $this->payload([
            'obyek_review' => 'Firewall',
            'items' => [
                ['user_log' => 'netadmin', 'ip_address' => '10.10.2.1', 'tanggal' => '2026-09-15', 'waktu' => '09:00:30', 'durasi' => '30 menit'],
            ],
        ]));
        $update->assertOk()->assertJsonPath('data.obyek_review', 'Firewall');
        $this->assertCount(1, $update->json('data.items'));

        $this->deleteJson("/api/smki/review-log/{$id}")->assertOk();
        $this->getJson("/api/smki/review-log/{$id}")->assertStatus(404);
    }

    public function test_validation_errors(): void
    {
        $this->actingBidangUser();

        $this->postJson('/api/smki/review-log', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['tanggal_review', 'obyek_review', 'pelaksana_review']);

        $this->postJson('/api/smki/review-log', $this->payload(['periode_selesai' => '2026-08-01']))
            ->assertStatus(422)->assertJsonValidationErrors(['periode_selesai']);

        $bad = $this->payload();
        $bad['items'][0]['waktu'] = '25:99';
        $this->postJson('/api/smki/review-log', $bad)
            ->assertStatus(422)->assertJsonValidationErrors(['items.0.waktu']);
    }

    public function test_data_isolated_per_bidang(): void
    {
        $this->actingBidangUser('A');
        $id = $this->postJson('/api/smki/review-log', $this->payload())->json('data.id');

        $this->actingBidangUser('B');
        $this->getJson('/api/smki/review-log')->assertOk()->assertJsonPath('stats.total_review', 0);
        $this->getJson("/api/smki/review-log/{$id}")->assertStatus(404);
    }

    public function test_export_docx_fills_original_template(): void
    {
        $this->actingBidangUser();
        $id = $this->postJson('/api/smki/review-log', $this->payload([
            'dibuat_oleh_nama' => 'Budi Santoso',
            'dibuat_oleh_nip'  => '199001012015011001',
            'mengetahui_nama'  => 'Kepala Bidang Aptika',
        ]))->json('data.id');

        $response = $this->get("/api/smki/review-log/{$id}/export-docx");
        $response->assertOk();
        $file = $response->baseResponse->getFile()->getPathname();
        if (($out = getenv('EXPORT_SAMPLE_DIR')) !== false && $out !== '') {
            copy($file, rtrim($out, '/') . '/fr040-sample.docx');
        }

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($file) === true);
        $text = strip_tags(str_replace('</w:p>', "\n", $zip->getFromName('word/document.xml')));
        $zip->close();

        $this->assertStringContainsString('FORMULIR REVIEW LOG', $text);
        $this->assertStringContainsString('FR-040/KOM.03.05/SANDIKAMI', $text);
        $this->assertStringContainsString('Tanggal Review : 1 Oktober 2026', preg_replace('/\x{00A0}/u', ' ', $text));
        $this->assertStringContainsString('Periode Review : 1 September 2026 s.d. 30 September 2026', preg_replace('/\x{00A0}/u', ' ', $text));
        $this->assertStringContainsString('admin01', $text);
        $this->assertStringContainsString('10.10.1.9', $text);
        $this->assertStringContainsString('Menonaktifkan akun yang tidak dikenal.', $text);
        $this->assertStringContainsString('Bandung, 2 Oktober 2026', $text);
        $this->assertStringContainsString('(Budi Santoso)', $text);
        $this->assertStringContainsString('NIP. 199001012015011001', $text);
    }

    public function test_export_docx_returns_404_for_unknown_id(): void
    {
        $this->actingBidangUser();
        $this->getJson('/api/smki/review-log/9999/export-docx')->assertStatus(404);
    }
}
