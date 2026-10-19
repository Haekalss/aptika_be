<?php

namespace Tests\Feature;

use App\Models\Bidang;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SmkiReviuCctvTest extends TestCase
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
            'tanggal_reviu'   => '2026-10-17',
            'periode_mulai'   => '2026-09-01',
            'periode_selesai' => '2026-09-30',
            'obyek_reviu'     => 'CCTV Ruang Server Lantai 2',
            'pelaksana_reviu' => 'Tim Keamanan Fisik',
            'pengelola_nama'  => 'Budi Santoso',
            'pengelola_nip'   => '198001012010011001',
            'items' => [
                ['uraian_reviu' => 'Kamera 3 tidak merekam malam hari', 'tindak_lanjut' => 'Ganti kamera', 'target_selesai' => '2099-01-15', 'keterangan' => 'Prioritas'],
                ['uraian_reviu' => 'Penyimpanan rekaman tersisa 10%', 'tindak_lanjut' => null, 'target_selesai' => null, 'keterangan' => null],
            ],
        ], $override);
    }

    public function test_crud_reviu_cctv_works(): void
    {
        $this->actingBidangUser();

        $create = $this->postJson('/api/smki/reviu-cctv', $this->payload());
        $create->assertStatus(201)->assertJsonPath('data.obyek_reviu', 'CCTV Ruang Server Lantai 2');
        $id = $create->json('data.id');
        $this->assertCount(2, $create->json('data.items'));

        $this->getJson('/api/smki/reviu-cctv')
            ->assertOk()
            ->assertJsonPath('stats.total_reviu', 1)
            ->assertJsonPath('stats.total_uraian', 2)
            ->assertJsonPath('stats.tindak_lanjut_aktif', 1)
            ->assertJsonPath('data.0.total_uraian', 2);

        $this->getJson('/api/smki/reviu-cctv?search=tersisa')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/smki/reviu-cctv?search=tidak-ada-xyz')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/smki/reviu-cctv?tanggal_dari=2026-11-01')->assertOk()->assertJsonPath('meta.total', 0);

        $this->getJson("/api/smki/reviu-cctv/{$id}")->assertOk()->assertJsonCount(2, 'data.items');

        $update = $this->putJson("/api/smki/reviu-cctv/{$id}", $this->payload([
            'items' => [['uraian_reviu' => 'Semua kamera normal']],
        ]));
        $update->assertOk();
        $this->assertCount(1, $update->json('data.items'));

        $this->deleteJson("/api/smki/reviu-cctv/{$id}")->assertOk();
        $this->getJson("/api/smki/reviu-cctv/{$id}")->assertStatus(404);
    }

    public function test_validation_errors(): void
    {
        $this->actingBidangUser();

        $this->postJson('/api/smki/reviu-cctv', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['tanggal_reviu', 'obyek_reviu', 'pelaksana_reviu', 'items']);

        $bad = $this->payload(['periode_selesai' => '2026-08-01']);
        $bad['items'][0]['uraian_reviu'] = '';
        $this->postJson('/api/smki/reviu-cctv', $bad)
            ->assertStatus(422)->assertJsonValidationErrors(['periode_selesai', 'items.0.uraian_reviu']);
    }

    public function test_data_isolated_per_bidang(): void
    {
        $this->actingBidangUser('A');
        $id = $this->postJson('/api/smki/reviu-cctv', $this->payload())->json('data.id');

        $this->actingBidangUser('B');
        $this->getJson('/api/smki/reviu-cctv')->assertOk()->assertJsonPath('stats.total_reviu', 0);
        $this->getJson("/api/smki/reviu-cctv/{$id}")->assertStatus(404);
    }

    public function test_export_docx_fills_original_template(): void
    {
        $this->actingBidangUser();
        $id = $this->postJson('/api/smki/reviu-cctv', $this->payload())->json('data.id');

        $response = $this->get("/api/smki/reviu-cctv/{$id}/export-docx");
        $response->assertOk();
        $file = $response->baseResponse->getFile()->getPathname();
        if (($out = getenv('EXPORT_SAMPLE_DIR')) !== false && $out !== '') {
            copy($file, rtrim($out, '/') . '/fr038-sample.docx');
        }

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($file) === true);
        $xml = $zip->getFromName('word/document.xml');
        $text = strip_tags(str_replace('</w:p>', "\n", $xml));
        $zip->close();

        $this->assertStringContainsString('FORMULIR REVIU', $text);
        $this->assertStringContainsString('17 Oktober 2026', $text);
        $this->assertStringContainsString('1 September 2026 s.d. 30 September 2026', $text);
        $this->assertStringContainsString('CCTV Ruang Server Lantai 2', $text);
        $this->assertStringContainsString('Tim Keamanan Fisik', $text);
        $this->assertStringContainsString('Kamera 3 tidak merekam malam hari', $text);
        $this->assertStringContainsString('Ganti kamera', $text);
        $this->assertStringContainsString('15-Jan-2099', $text);
        $this->assertStringContainsString('(Budi Santoso)', $text);
        $this->assertStringContainsString('NIP. 198001012010011001', $text);
        $this->assertStringContainsString('Pengelola CCTV', $text);
        // orientasi landscape asli dipertahankan
        $this->assertStringContainsString('w:orient="landscape"', $xml);
    }

    public function test_export_docx_adds_rows_beyond_template_minimum(): void
    {
        $this->actingBidangUser();
        $items = [];
        for ($i = 1; $i <= 6; $i++) {
            $items[] = ['uraian_reviu' => "Uraian nomor {$i}"];
        }
        $id = $this->postJson('/api/smki/reviu-cctv', $this->payload(['items' => $items]))->json('data.id');

        $file = $this->get("/api/smki/reviu-cctv/{$id}/export-docx")->baseResponse->getFile()->getPathname();
        $zip = new \ZipArchive();
        $zip->open($file);
        $text = strip_tags(str_replace('</w:p>', "\n", $zip->getFromName('word/document.xml')));
        $zip->close();
        $this->assertStringContainsString('Uraian nomor 6', $text);
    }

    public function test_export_docx_returns_404_for_unknown_id(): void
    {
        $this->actingBidangUser();
        $this->getJson('/api/smki/reviu-cctv/9999/export-docx')->assertStatus(404);
    }
}
