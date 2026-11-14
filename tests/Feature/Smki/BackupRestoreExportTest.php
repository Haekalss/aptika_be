<?php

namespace Tests\Feature\Smki;

use App\Models\SmkiBackupRestoreAttachment;
use App\Models\SmkiBackupRestoreReport;
use App\Services\Smki\SmkiBackupRestoreDocxService;
use App\Services\TteSpecimenService;
use DOMDocument;
use DOMXPath;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BackupRestoreTestCase;
use ZipArchive;

class BackupRestoreExportTest extends BackupRestoreTestCase
{
    public static function assetCounts(): array
    {
        return ['empty draft' => [0, 7], 'original slots filled' => [5, 7], 'maximum assets' => [50, 52]];
    }

    #[DataProvider('assetCounts')]
    public function test_asset_counts_preserve_slots_without_dropping_real_assets(int $count, int $rowCount): void
    {
        $this->actingAs($this->account(), 'sanctum');
        $data = $this->payload();
        $asset = $data['assets'][0];
        $data['assets'] = [];
        for ($index = 1; $index <= $count; $index++) {
            $data['assets'][] = [...$asset, 'client_key' => (string) Str::uuid(), 'nama_aset' => 'Aset contoh '.$index];
        }
        $record = $this->postJson('/api/smki/backup-restore', $data)->assertCreated()->json('data');
        $path = $this->get('/api/smki/backup-restore/'.$record['id'].'/export-docx')->assertOk()->baseResponse->getFile()->getPathname();
        try {
            $zip = new ZipArchive;
            $zip->open($path);
            $doc = new DOMDocument;
            $doc->loadXML($zip->getFromName('word/document.xml'));
            $xp = new DOMXPath($doc);
            $xp->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
            $this->assertCount($rowCount, $xp->query('/w:document/w:body/w:tbl[1]/w:tr'));
            for ($index = 1; $index <= $count; $index++) {
                $this->assertSame($index.'. Aset contoh '.$index, $xp->query('/w:document/w:body/w:tbl[1]/w:tr['.($index + 2).']/w:tc[1]')->item(0)->textContent);
            }
            if ($count === 0) {
                foreach ($xp->query('/w:document/w:body/w:tbl[1]/w:tr[position() > 2]/w:tc') as $cell) {
                    $this->assertSame('', $cell->textContent, 'Unused template slots must not invent assets or activities.');
                }
            }
            $zip->close();
        } finally { @unlink($path); }
    }

    public function test_export_groups_evidence_without_repeated_results_and_preserves_template_layout(): void
    {
        Storage::fake('local');
        $this->actingAs($this->account(), 'sanctum');
        $data = $this->payload();
        $data['assets'][0]['entries'] = [];
        foreach ([1, 2, 3] as $month) {
            foreach (['backup', 'restore'] as $operation) {
                $data['assets'][0]['entries'][] = ['month' => $month, 'operation' => $operation, 'result' => 'berhasil', 'performed_on' => sprintf('2026-%02d-03', $month), 'notes' => 'Catatan penting '.$month];
            }
        }
        $created = $this->postJson('/api/smki/backup-restore', $data)->assertCreated()->json('data');
        $path = $this->get('/api/smki/backup-restore/'.$created['id'].'/export-docx')->assertOk()->baseResponse->getFile()->getPathname();
        try {
            $zip = new ZipArchive;
            $zip->open($path);
            $doc = new DOMDocument;
            $doc->loadXML($zip->getFromName('word/document.xml'));
            $xp = new DOMXPath($doc);
            $xp->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
            $xp->registerNamespace('wp', 'http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing');
            $this->assertStringNotContainsString('(hasil:', $doc->textContent);
            foreach (['Januari', 'Februari', 'Maret'] as $month) {
                $this->assertSame(1, substr_count($doc->textContent, '1. Switch - '.$month.' 2026'));
            }
            foreach ([1, 2, 3] as $month) {
                $monthLabel = [1 => 'Januari', 2 => 'Februari', 3 => 'Maret'][$month];
                $cell = $xp->query('/w:document/w:body/w:tbl[1]/w:tr[3]/w:tc['.($month + 1).']')->item(0)->textContent;
                $this->assertSame('B: ✓R: ✓', $cell);
                $this->assertStringContainsString('Backup | Berhasil | 3 '.$monthLabel.' 2026', $doc->textContent);
                $this->assertStringContainsString('Restore | Berhasil | 3 '.$monthLabel.' 2026', $doc->textContent);
            }
            $original = new ZipArchive;
            $original->open(resource_path('templates/FR-030 Formulir Berita Acara Backup.docx'));
            $source = new DOMDocument;
            $source->loadXML($original->getFromName('word/document.xml'));
            $sx = new DOMXPath($source);
            $sx->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
            foreach ([
                '/w:document/w:body/w:tbl[1]/w:tblPr',
                '/w:document/w:body/w:tbl[1]/w:tblGrid',
                '/w:document/w:body/w:tbl[2]/w:tblPr',
                '/w:document/w:body/w:tbl[2]/w:tblGrid',
                '/w:document/w:body/w:tbl[2]/w:tr[2]/w:trPr/w:trHeight',
                '//w:sectPr/w:pgSz', '//w:sectPr/w:pgMar',
                '/w:document/w:body/w:tbl[1]/w:tr[3]/w:tc[1]/w:p/w:pPr/w:spacing',
            ] as $query) {
                $actual = $xp->query($query)->item(0);
                $expected = $sx->query($query)->item(0);
                $this->assertNotNull($actual, $query);
                $this->assertSame($expected->C14N(), $actual->C14N(), $query);
            }
            $this->assertCount(7, $xp->query('/w:document/w:body/w:tbl[1]/w:tr'), 'Keep five original asset slots for a short report.');
            $this->assertCount(2, $xp->query('/w:document/w:body/w:tbl[1]/preceding-sibling::w:p'));
            $this->assertCount(2, $xp->query('/w:document/w:body/w:tbl[2]/preceding-sibling::w:p[preceding-sibling::w:tbl]'));
            $this->assertCount(0, $xp->query('/w:document/w:body/w:tbl[1]//w:rPr/w:sz[@w:val="18"]'), 'Do not shrink source typography to nine points.');
            $this->assertStringNotContainsString('SWITCH 02', $xp->query('/w:document/w:body/w:tbl[1]')->item(0)->textContent);
            foreach ($xp->query('/w:document/w:body/w:tbl[2]//wp:extent') as $image) {
                $this->assertLessThanOrEqual(1905000, (int) $image->getAttribute('cx'), 'Specimen must fit the original narrow signer cells.');
            }
            $original->close();
            $zip->close();
        } finally { @unlink($path); }
    }

    public function test_monthly_symbols_keep_failed_incomplete_and_unrecorded_activities_distinct(): void
    {
        $this->actingAs($this->account(), 'sanctum');
        $data = $this->payload();
        $data['assets'][0]['entries'] = [
            ['month' => 1, 'operation' => 'backup', 'result' => 'berhasil', 'performed_on' => '2026-01-31', 'notes' => null],
            ['month' => 1, 'operation' => 'restore', 'result' => 'gagal', 'performed_on' => '2026-01-30', 'notes' => 'Perlu pengujian ulang'],
            ['month' => 2, 'operation' => 'backup', 'result' => 'berhasil', 'performed_on' => null, 'notes' => null],
            ['month' => 2, 'operation' => 'restore', 'result' => null, 'performed_on' => null, 'notes' => '0'],
        ];
        $record = $this->postJson('/api/smki/backup-restore', $data)->assertCreated()->json('data');
        $path = $this->get('/api/smki/backup-restore/'.$record['id'].'/export-docx')->assertOk()->baseResponse->getFile()->getPathname();
        try {
            $zip = new ZipArchive;
            $zip->open($path);
            $dom = new DOMDocument;
            $dom->loadXML($zip->getFromName('word/document.xml'));
            $xp = new DOMXPath($dom);
            $xp->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
            $this->assertSame('B: ✓R: ×', $xp->query('/w:document/w:body/w:tbl[1]/w:tr[3]/w:tc[2]')->item(0)->textContent);
            $this->assertSame('B: ?R: ?', $xp->query('/w:document/w:body/w:tbl[1]/w:tr[3]/w:tc[3]')->item(0)->textContent);
            $this->assertSame('B: –R: –', $xp->query('/w:document/w:body/w:tbl[1]/w:tr[3]/w:tc[4]')->item(0)->textContent);
            $this->assertCount(1, $xp->query('/w:document/w:body/w:tbl[1]/w:tr[3]/w:tc[2]//w:br'), 'B and R each get their own line.');
            $matrix = $xp->query('/w:document/w:body/w:tbl[1]')->item(0)->textContent;
            $this->assertStringNotContainsString('31/01', $matrix);
            $this->assertStringNotContainsString('Berhasil', $matrix);
            $this->assertStringContainsString('Restore | Gagal | 30 Januari 2026', $dom->textContent);
            $this->assertStringContainsString('Catatan: 0', $dom->textContent);
            $this->assertStringContainsString('✓ = Berhasil', $dom->textContent);
            $this->assertStringContainsString('× = Gagal', $dom->textContent);
            $zip->close();
        } finally { @unlink($path); }
    }

    public function test_zero_note_and_extreme_aspect_image_are_visible_in_export(): void
    {
        Storage::fake('local');
        $this->actingAs($this->account(), 'sanctum');
        $data = $this->payload();
        $data['assets'][0]['entries'][0]['notes'] = '0';
        $record = $this->postJson('/api/smki/backup-restore', $data)->assertCreated()->json('data');
        $this->post('/api/smki/backup-restore/'.$record['id'].'/entries/'.$record['assets'][0]['entries'][0]['id'].'/attachments', ['file' => UploadedFile::fake()->image('thin.png', 6000, 1), 'version' => 1], ['Accept' => 'application/json'])->assertCreated();
        $path = $this->get('/api/smki/backup-restore/'.$record['id'].'/export-docx')->assertOk()->baseResponse->getFile()->getPathname();
        try {
            $zip = new ZipArchive;
            $zip->open($path);
            $dom = new DOMDocument;
            $dom->loadXML($zip->getFromName('word/document.xml'));
            $xp = new DOMXPath($dom);
            $xp->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
            $xp->registerNamespace('wp', 'http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing');
            $this->assertCount(1, $xp->query('//w:p[w:r/w:t="Catatan: 0"]'));
            foreach ($xp->query('//wp:extent') as $extent) {
                $this->assertGreaterThan(0, (int) $extent->getAttribute('cx'));
                $this->assertGreaterThan(0, (int) $extent->getAttribute('cy'));
            }
            $zip->close();
        } finally {
            @unlink($path);
        }
    }

    public function test_preview_uses_frozen_identity_and_denies_other_bidang(): void
    {
        $this->actingAs($this->account(), 'sanctum');
        $record = $this->postJson('/api/smki/backup-restore', $this->payload())->assertCreated()->json('data');
        DB::table('pegawai')->where('id', 2)->delete();
        $image = $this->get('/api/smki/backup-restore/'.$record['id'].'/signatories/pelaksana/specimen')->assertOk()->assertHeader('Content-Type', 'image/png');
        $expected = app(TteSpecimenService::class)->generate(['nama' => 'Budi Santoso', 'nip' => '002', 'jabatan' => 'Kepala', 'pangkat' => 'IVa']);
        $this->assertSame(hash_file('sha256', $expected), hash_file('sha256', $image->baseResponse->getFile()->getPathname()));
        $this->get('/api/smki/backup-restore/'.$record['id'].'/signatories/invalid/specimen')->assertNotFound();
        $this->actingAs($this->account('OTHER', 'admin'), 'sanctum');
        $this->get('/api/smki/backup-restore/'.$record['id'].'/signatories/pelaksana/specimen')->assertNotFound();
        $this->get('/api/smki/backup-restore/'.$record['id'].'/export-docx')->assertNotFound();
    }

    public function test_docx_fills_original_slots_and_embeds_signers_notes_and_evidence(): void
    {
        Storage::fake('local');
        $this->actingAs($this->account(), 'sanctum');
        $data = $this->payload();
        $data['assets'][0]['nama_aset'] = 'SW & <Unicode é>';
        $data['assets'][0]['entries'][0]['notes'] = str_repeat('Catatan lengkap & aman. ', 50);
        $data['assets'][0]['entries'][] = ['month' => 10, 'operation' => 'restore', 'result' => 'gagal', 'performed_on' => '2026-10-04', 'notes' => 'Restore perlu diulang'];
        $record = $this->postJson('/api/smki/backup-restore', $data)->assertCreated()->json('data');
        $this->post('/api/smki/backup-restore/'.$record['id'].'/entries/'.$record['assets'][0]['entries'][0]['id'].'/attachments', ['file' => UploadedFile::fake()->image('bukti.jpg', 80, 60), 'version' => 1], ['Accept' => 'application/json'])->assertCreated();
        $source = resource_path('templates/FR-030 Formulir Berita Acara Backup.docx');
        $hash = hash_file('sha256', $source);
        $response = $this->get('/api/smki/backup-restore/'.$record['id'].'/export-docx')->assertOk();
        $path = $response->baseResponse->getFile()->getPathname();
        try {
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($path));
            $dom = new DOMDocument;
            $this->assertTrue($dom->loadXML($zip->getFromName('word/document.xml')));
            $xp = new DOMXPath($dom);
            $xp->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
            $text = $dom->textContent;
            foreach (['SW & <Unicode é>', 'B: ✓', 'R: ×', '3 Oktober 2026', '4 Oktober 2026', 'Restore perlu diulang', trim($data['assets'][0]['entries'][0]['notes'])] as $value) {
                $this->assertStringContainsString($value, $text);
            }
            $this->assertStringNotContainsString('SWITCH 02', $text);
            $this->assertStringNotContainsString('Budi Santoso', $text, 'Signer name is already inside the image, not repeated as text.');
            $this->assertCount(3, $xp->query('//w:drawing'));
            $this->assertCount(2, $xp->query('/w:document/w:body/w:tbl[1]/w:tr[position() <= 2]/w:trPr/w:tblHeader'), 'Repeating month headings require both leading rows to repeat.');
            $this->assertCount(1, $xp->query('/w:document/w:body/w:p[starts-with(normalize-space(.), "LAMPIRAN")]/w:pPr/w:pageBreakBefore'), 'Appendix starts directly on a new page, without inherited spacer paragraphs.');
            $this->assertCount(7, $xp->query('/w:document/w:body/w:tbl[1]/w:tr'), 'Original five asset slots remain, with unused slots blank.');
            $this->assertCount(0, $xp->query('/w:document/w:body//w:p[not(w:pPr/w:keepNext)]'), 'Body paragraphs must not inherit the template keep-next chain that creates blank pages.');
            $this->assertCount(2, $xp->query('/w:document/w:body/w:tbl[2]/w:tr[1]//w:keepNext[@w:val="1"]'), 'Signer headings stay with their images.');
            $this->assertStringContainsString('B = Backup', $dom->textContent, 'Legend is in appendix, not inserted into the original pre-signer spacing.');
            $this->assertCount(1, $xp->query('/w:document/w:body/w:p[w:r/w:drawing]/preceding-sibling::w:p[1]/w:pPr/w:keepNext[@w:val="1"]'), 'Evidence captions stay with their image.');
            $this->assertSame('20163', $xp->query('//w:pgSz')->item(0)->getAttributeNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w'));
            $headerReferences = $xp->query('//w:sectPr/w:headerReference');
            $this->assertSame($headerReferences->item(0)->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id'), $headerReferences->item(1)->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id'), 'All pages use the same document header.');
            foreach (['word/header1.xml', 'word/header2.xml'] as $part) {
                $header = new DOMDocument;
                $header->loadXML($zip->getFromName($part));
                $headerXPath = new DOMXPath($header);
                $headerXPath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
                $this->assertCount(0, $headerXPath->query('//w:tblpPr'), 'A floating header can disappear or overlap continuation-page text.');
                $this->assertStringContainsString('FR-030/TEST', $header->textContent);
                $this->assertStringNotContainsString('FR-034', $header->textContent);
            }
            $original = new ZipArchive;
            $original->open($source);
            foreach (['word/styles.xml', 'word/footer1.xml', 'word/_rels/header1.xml.rels', 'word/_rels/header2.xml.rels'] as $part) {
                $this->assertSame($original->getFromName($part), $zip->getFromName($part));
            }
            $original->close();
            $zip->close();
        } finally {
            @unlink($path);
        }
        $this->assertSame($hash, hash_file('sha256', $source));
    }

    public function test_blank_and_partial_draft_never_invents_a_signer_or_success(): void
    {
        $this->actingAs($this->account(), 'sanctum');
        $data = $this->payload(['pelaksana_pegawai_id' => null, 'penanggung_jawab_pegawai_id' => null]);
        $data['assets'][0]['entries'][0]['performed_on'] = null;
        $record = $this->postJson('/api/smki/backup-restore', $data)->assertCreated()->json('data');
        $response = $this->get('/api/smki/backup-restore/'.$record['id'].'/export-docx')->assertOk();
        $path = $response->baseResponse->getFile()->getPathname();
        try {
            $zip = new ZipArchive;
            $zip->open($path);
            $dom = new DOMDocument;
            $dom->loadXML($zip->getFromName('word/document.xml'));
            $this->assertStringContainsString('B: ?', $dom->textContent);
            $this->assertStringContainsString('DRAF', $dom->textContent);
            $this->assertStringNotContainsString('B: Berhasil', $dom->textContent);
            $this->assertStringNotContainsString('Budi', $dom->textContent);
            $zip->close();
        } finally {
            @unlink($path);
        }
    }

    public function test_renderer_failure_does_not_silently_export_without_selected_signer(): void
    {
        $this->actingAs($this->account(), 'sanctum');
        $record = $this->postJson('/api/smki/backup-restore', $this->payload())->assertCreated()->json('data');
        $this->mock(TteSpecimenService::class)->shouldReceive('generate')->andReturn(null);
        $this->get('/api/smki/backup-restore/'.$record['id'].'/export-docx')->assertStatus(503);
        $this->get('/api/smki/backup-restore/'.$record['id'].'/signatories/pelaksana/specimen')->assertStatus(503);
    }

    public function test_repeated_maximum_exports_preserve_every_image_and_failed_export_leaves_no_temporary_file(): void
    {
        Storage::fake('local');
        $this->actingAs($this->account(), 'sanctum');
        $data = $this->payload();
        $template = $data['assets'][0];
        $data['assets'] = [];
        for ($index = 1; $index <= 50; $index++) {
            $data['assets'][] = [...$template, 'client_key' => (string) Str::uuid(), 'nama_aset' => 'Aset maksimum '.$index];
        }
        $record = $this->postJson('/api/smki/backup-restore', $data)->assertCreated()->json('data');
        foreach ($record['assets'] as $asset) {
            $path = 'smki/backup-restore/'.$record['id'].'/proof-'.$asset['id'].'.jpg';
            $image = UploadedFile::fake()->image('image.jpg', 60, 40);
            Storage::disk('local')->put($path, file_get_contents($image->getPathname()));
            SmkiBackupRestoreAttachment::create(['entry_id' => $asset['entries'][0]['id'], 'disk' => 'local', 'path' => $path, 'mime' => 'image/jpeg', 'size' => Storage::disk('local')->size($path), 'original_name' => 'proof-'.$asset['id'].'.jpg']);
        }
        $service = app(SmkiBackupRestoreDocxService::class);
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $output = $service->generateDocx(SmkiBackupRestoreReport::findOrFail($record['id']));
            try {
                $zip = new ZipArchive;
                $zip->open($output);
                $dom = new DOMDocument;
                $this->assertTrue($dom->loadXML($zip->getFromName('word/document.xml')));
                $xp = new DOMXPath($dom);
                $xp->registerNamespace('a', 'http://schemas.openxmlformats.org/drawingml/2006/main');
                $this->assertCount(52, $xp->query('//a:blip'));
                $rels = new DOMDocument;
                $rels->loadXML($zip->getFromName('word/_rels/document.xml.rels'));
                $targets = [];
                foreach ($rels->documentElement->childNodes as $relationship) {
                    if ($relationship instanceof \DOMElement) {
                        $targets[$relationship->getAttribute('Id')] = $relationship->getAttribute('Target');
                    }
                }
                foreach ($xp->query('//a:blip') as $image) {
                    $target = $targets[$image->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'embed')] ?? null;
                    $this->assertNotNull($target);
                    $this->assertNotFalse($zip->getFromName('word/'.$target));
                }
                $this->assertStringContainsString('Aset maksimum 50', $dom->textContent);
                $zip->close();
            } finally {
                @unlink($output);
            }
        }
        Storage::disk('local')->delete('smki/backup-restore/'.$record['id'].'/proof-'.$record['assets'][0]['id'].'.jpg');
        $before = glob(storage_path('app/private/fr030-exports/*.docx'));
        $this->get('/api/smki/backup-restore/'.$record['id'].'/export-docx')->assertStatus(503);
        $this->assertSame($before, glob(storage_path('app/private/fr030-exports/*.docx')));
    }
}
