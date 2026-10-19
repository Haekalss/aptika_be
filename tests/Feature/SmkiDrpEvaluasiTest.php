<?php

namespace Tests\Feature;

use App\Models\Bidang;
use App\Models\SmkiDrpEvaluasi;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SmkiDrpEvaluasiTest extends TestCase
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
            'tanggal_pengujian'  => '2026-10-17',
            'waktu_mulai'        => '09:00',
            'waktu_selesai'      => '12:30',
            'tujuan'             => 'Menguji pemulihan sistem SIMPEG',
            'cakupan'            => 'Server aplikasi dan basis data',
            'tipe_pengujian'     => 'Simulasi (tabletop)',
            'asumsi_bencana'     => 'Pusat data utama tidak dapat diakses',
            'asumsi_sumber_daya' => 'Seluruh tim hadir',
            'checklist'          => ['kelengkapan_1' => true, 'kejelasan_2' => true, 'eksekusi_4' => false, 'kunci_palsu' => true],
            'lampiran'           => "1. Log failover\n2. Foto dokumentasi",
            'dibuat_oleh_nama'       => 'Budi Santoso',
            'dibuat_oleh_tanggal'    => '2026-10-17',
            'diperiksa_oleh_nama'    => 'Siti Aminah',
            'diperiksa_oleh_tanggal' => '2026-10-18',
            'disetujui_oleh_nama'    => 'Kepala Bidang',
            'disetujui_oleh_tanggal' => '2026-10-19',
            'waktu' => [
                ['sistem_ti' => 'SIMPEG', 'rto_ideal' => '4 jam', 'pencapaian_rto' => '5 jam', 'kendala' => 'Restore database lambat'],
                ['sistem_ti' => 'Portal Pegawai', 'rto_ideal' => '2 jam', 'pencapaian_rto' => '1 jam 30 menit', 'kendala' => null],
            ],
            'proses' => [
                ['proses' => 'Failover server', 'aktivitas' => 'Aktifkan server DRC', 'hasil_evaluasi' => 'Lambat', 'rekomendasi' => 'Otomatisasi skrip', 'pic' => 'Tim Infra'],
            ],
            'tim' => [
                ['peran' => 'Koordinator DRP', 'tanggung_jawab' => 'Memimpin pengujian', 'evaluasi' => 'Baik', 'rekomendasi' => 'Pelatihan'],
                ['peran' => 'Administrator Basis Data', 'tanggung_jawab' => 'Restore database', 'evaluasi' => 'Cukup', 'rekomendasi' => null],
            ],
        ], $override);
    }

    public function test_crud_evaluasi_drp_works(): void
    {
        $this->actingBidangUser();

        $create = $this->postJson('/api/smki/evaluasi-drp', $this->payload());
        $create->assertStatus(201)->assertJsonPath('data.tipe_pengujian', 'Simulasi (tabletop)');
        $id = $create->json('data.id');
        $this->assertCount(2, $create->json('data.waktu'));
        $this->assertCount(1, $create->json('data.proses'));
        $this->assertCount(2, $create->json('data.tim'));

        // checklist hanya berisi 10 kriteria resmi (kunci asing dibuang)
        $checklist = $create->json('data.checklist');
        $this->assertCount(count(SmkiDrpEvaluasi::CHECKLIST), $checklist);
        $this->assertTrue($checklist['kelengkapan_1']);
        $this->assertFalse($checklist['eksekusi_4']);
        $this->assertArrayNotHasKey('kunci_palsu', $checklist);

        $this->getJson('/api/smki/evaluasi-drp')
            ->assertOk()
            ->assertJsonPath('stats.total_evaluasi', 1)
            ->assertJsonPath('stats.total_sistem', 2)
            ->assertJsonPath('stats.total_rekomendasi', 1)
            ->assertJsonPath('data.0.total_proses', 1)
            ->assertJsonPath('data.0.total_tim', 2);

        $this->getJson('/api/smki/evaluasi-drp?search=Administrator')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/smki/evaluasi-drp?search=tidak-ada-xyz')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/smki/evaluasi-drp?tanggal_sampai=2026-01-01')->assertOk()->assertJsonPath('meta.total', 0);

        $this->getJson("/api/smki/evaluasi-drp/{$id}")
            ->assertOk()
            ->assertJsonCount(2, 'data.waktu')
            ->assertJsonCount(10, 'checklist_kriteria');

        $update = $this->putJson("/api/smki/evaluasi-drp/{$id}", $this->payload([
            'waktu' => [], 'proses' => [], 'tim' => [['peran' => 'Pelaksana']],
        ]));
        $update->assertOk();
        $this->assertCount(0, $update->json('data.waktu'));
        $this->assertCount(1, $update->json('data.tim'));

        $this->deleteJson("/api/smki/evaluasi-drp/{$id}")->assertOk();
        $this->getJson("/api/smki/evaluasi-drp/{$id}")->assertStatus(404);
    }

    public function test_validation_errors(): void
    {
        $this->actingBidangUser();

        $this->postJson('/api/smki/evaluasi-drp', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['tanggal_pengujian', 'tujuan', 'cakupan', 'tipe_pengujian']);

        $bad = $this->payload(['waktu_mulai' => '9 pagi']);
        $bad['waktu'][0]['sistem_ti'] = '';
        $bad['proses'][0]['proses'] = '';
        $bad['tim'][0]['peran'] = '';
        $this->postJson('/api/smki/evaluasi-drp', $bad)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['waktu_mulai', 'waktu.0.sistem_ti', 'proses.0.proses', 'tim.0.peran']);
    }

    public function test_data_isolated_per_bidang(): void
    {
        $this->actingBidangUser('A');
        $id = $this->postJson('/api/smki/evaluasi-drp', $this->payload())->json('data.id');

        $this->actingBidangUser('B');
        $this->getJson('/api/smki/evaluasi-drp')->assertOk()->assertJsonPath('stats.total_evaluasi', 0);
        $this->getJson("/api/smki/evaluasi-drp/{$id}")->assertStatus(404);
    }

    public function test_export_docx_fills_original_template(): void
    {
        $this->actingBidangUser();
        $id = $this->postJson('/api/smki/evaluasi-drp', $this->payload())->json('data.id');

        $response = $this->get("/api/smki/evaluasi-drp/{$id}/export-docx");
        $response->assertOk();
        $file = $response->baseResponse->getFile()->getPathname();
        if (($out = getenv('EXPORT_SAMPLE_DIR')) !== false && $out !== '') {
            copy($file, rtrim($out, '/') . '/fr039-sample.docx');
        }

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($file) === true);
        $xml = $zip->getFromName('word/document.xml');
        $header = $zip->getFromName('word/header1.xml');
        $zip->close();
        $text = strip_tags(str_replace('</w:p>', "\n", $xml));

        $this->assertStringContainsString('FR-039/KOM.03.05/SANDIKAMI', strip_tags($header));
        $this->assertStringContainsString('17 Oktober 2026, 09:00 - 12:30 WIB', $text);
        $this->assertStringContainsString('Menguji pemulihan sistem SIMPEG', $text);
        $this->assertStringContainsString('Server aplikasi dan basis data', $text);
        $this->assertStringContainsString('Simulasi (tabletop)', $text);
        $this->assertStringContainsString('Pusat data utama tidak dapat diakses', $text);
        $this->assertStringContainsString('Seluruh tim hadir', $text);
        $this->assertStringNotContainsString('[Berisikan', $text);
        $this->assertStringContainsString('Portal Pegawai', $text);
        $this->assertStringContainsString('1 jam 30 menit', $text);
        $this->assertStringContainsString('Failover server', $text);
        $this->assertStringContainsString('Otomatisasi skrip', $text);
        $this->assertStringContainsString('Administrator Basis Data', $text);
        $this->assertStringContainsString('Tanggal: 18 Oktober 2026', $text);
        $this->assertStringContainsString('(Budi Santoso)', $text);
        $this->assertStringContainsString('1. Log failover', $text);
        // bagian statis (checklist BCP) tetap seperti aslinya
        $this->assertStringContainsString('Recovery Time Objectives (RTO) yang realistis', $text);
        $this->assertStringContainsString('Lampiran Laporan Pengujian DRP', $text);
        // tata letak: A4 potret, margin 720, tidak ada tabel melebihi area isi
        $this->assertStringContainsString('w:left="720"', $xml);
        preg_match_all('/<w:tblW w:w="(\d+)"/', $xml, $m);
        foreach ($m[1] as $w) {
            $this->assertLessThanOrEqual(10460, (int) $w);
        }
        // sel gabungan vertikal pada tabel proses/team sudah dilepas
        $this->assertStringNotContainsString('w:vMerge', $xml);
    }

    public function test_export_docx_adds_rows_beyond_template_minimum(): void
    {
        $this->actingBidangUser();
        $proses = $tim = $waktu = [];
        for ($i = 1; $i <= 11; $i++) {
            $proses[] = ['proses' => "Proses ke-{$i}"];
        }
        for ($i = 1; $i <= 7; $i++) {
            $tim[] = ['peran' => "Peran ke-{$i}"];
            $waktu[] = ['sistem_ti' => "Sistem ke-{$i}"];
        }
        $id = $this->postJson('/api/smki/evaluasi-drp', $this->payload(['proses' => $proses, 'tim' => $tim, 'waktu' => $waktu]))->json('data.id');

        $file = $this->get("/api/smki/evaluasi-drp/{$id}/export-docx")->baseResponse->getFile()->getPathname();
        $zip = new \ZipArchive();
        $zip->open($file);
        $text = strip_tags(str_replace('</w:p>', "\n", $zip->getFromName('word/document.xml')));
        $zip->close();
        $this->assertStringContainsString('Proses ke-11', $text);
        $this->assertStringContainsString('Peran ke-7', $text);
        $this->assertStringContainsString('Sistem ke-7', $text);
    }

    public function test_export_docx_returns_404_for_unknown_id(): void
    {
        $this->actingBidangUser();
        $this->getJson('/api/smki/evaluasi-drp/9999/export-docx')->assertStatus(404);
    }
}
