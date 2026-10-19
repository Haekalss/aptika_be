<?php

namespace Tests\Unit\Smki;

use App\Models\SmkiTindakanPerbaikan;
use App\Services\Documents\DocxToPdfService;
use App\Services\Smki\SmkiTindakanPerbaikanDocxService;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Tests\TestCase;
use ZipArchive;

class TindakanPerbaikanDocxTest extends TestCase
{
    private array $generatedPaths = [];

    protected function tearDown(): void
    {
        foreach ($this->generatedPaths as $path) {
            File::deleteDirectory(dirname($path));
        }
        parent::tearDown();
    }

    public function test_export_preserves_template_package_geometry_and_styles(): void
    {
        $templatePath = resource_path('templates/FR-009 Form Tindakan Perbaikan dan Pencegahan.docx');
        $beforeHash = hash_file('sha256', $templatePath);
        $source = $this->readPackage($templatePath);
        $output = $this->readPackage($this->generate($this->report()));

        $this->assertSame(array_keys($source), array_keys($output));
        foreach ($source as $part => $bytes) {
            if (! in_array($part, ['word/document.xml', 'word/footer1.xml', 'word/settings.xml'], true)) {
                $this->assertSame($bytes, $output[$part], 'Template part changed: '.$part);
            }
        }
        $sourceXpath = $this->xml($source['word/document.xml']);
        $outputXpath = $this->xml($output['word/document.xml']);
        foreach (['//w:sectPr', '//w:tblPr', '//w:tblGrid', '//w:tcPr', '//w:pPr'] as $expression) {
            $this->assertSame($this->canonicalNodes($sourceXpath, $expression), $this->canonicalNodes($outputXpath, $expression), $expression);
        }
        $this->assertSame($beforeHash, hash_file('sha256', $templatePath));
        $this->assertStringContainsString('12240', $outputXpath->query('//w:pgSz')->item(0)->C14N());
        $this->assertStringContainsString('15840', $outputXpath->query('//w:pgSz')->item(0)->C14N());
        $this->assertSame('1440', $outputXpath->query('//w:pgMar')->item(0)->getAttributeNS($this->namespace(), 'left'));
        $this->assertSame(2, $outputXpath->query('/w:document/w:body/w:tbl')->length);
        $this->assertSame(10, $outputXpath->query('/w:document/w:body/w:tbl[2]/w:tr')->length);
        $this->assertSame(1, $this->xml($output['word/footer1.xml'])->query('//w:fldSimple[contains(@w:instr,"NUMPAGES")]')->length);
        $this->assertSame('true', $this->xml($output['word/settings.xml'])->query('//w:updateFields')->item(0)->getAttributeNS($this->namespace(), 'val'));
    }

    public function test_dynamic_rows_fill_original_cells_without_losing_merge_grid_or_unicode(): void
    {
        $report = $this->report([
            'deskripsi' => "Masalah & <potensial> ñ\nBaris kedua\x01",
            'akar_masalah' => 'Akar: α & β',
            'sumber_codes' => ['audit_internal', 'lainnya'],
            'sumber_lainnya' => 'Saran & evaluasi',
            'verifikasi' => 'Close',
            'tanggal_verifikasi' => '2026-10-14',
            'catatan_verifikasi' => 'Tindakan sudah diuji.',
            'auditor_nama' => 'Auditor Nyata',
            'atasan_nama' => 'Atasan Nyata',
        ], [
            ['jenis' => 'koreksi', 'uraian' => 'Koreksi kedua', 'pic_nama' => 'PIC B', 'batas_waktu' => '2026-10-16', 'urutan' => 2],
            ['jenis' => 'koreksi', 'uraian' => "Koreksi pertama & <uji>\nLanjut", 'pic_nama' => 'PIC A', 'batas_waktu' => '2026-10-15', 'urutan' => 1],
            ['jenis' => 'tindakan_korektif', 'uraian' => 'Prosedur baru', 'pic_nama' => 'PIC C', 'batas_waktu' => null, 'urutan' => 1],
        ]);
        $package = $this->readPackage($this->generate($report));
        $xpath = $this->xml($package['word/document.xml']);
        $table = '/w:document/w:body/w:tbl[2]';
        $this->assertSame(11, $xpath->query($table.'/w:tr')->length);
        $this->assertSame('1', $xpath->query($table.'/w:tr[5]/w:tc[1]')->item(0)->textContent);
        $this->assertSame('2', $xpath->query($table.'/w:tr[6]/w:tc[1]')->item(0)->textContent);
        $this->assertSame('1', $xpath->query($table.'/w:tr[8]/w:tc[1]')->item(0)->textContent);
        $this->assertStringContainsString('Koreksi pertama & <uji>', $xpath->query($table.'/w:tr[5]/w:tc[2]')->item(0)->textContent);
        $this->assertSame('3', $xpath->query($table.'/w:tr[5]/w:tc[2]/w:tcPr/w:gridSpan')->item(0)->getAttributeNS($this->namespace(), 'val'));
        $this->assertSame('2', $xpath->query($table.'/w:tr[5]/w:tc[3]/w:tcPr/w:gridSpan')->item(0)->getAttributeNS($this->namespace(), 'val'));
        $this->assertStringContainsString('Masalah & <potensial> ñ', $xpath->document->textContent);
        $this->assertStringContainsString('Akar: α & β', $xpath->document->textContent);
        $this->assertStringContainsString('Saran & evaluasi', $xpath->document->textContent);
        $this->assertStringNotContainsString("\x01", $package['word/document.xml']);
        $this->assertGreaterThanOrEqual(2, $xpath->query('//w:br')->length);
        $this->assertSame('X', $xpath->query('/w:document/w:body/w:tbl[1]/w:tr[2]/w:tc[1]')->item(0)->textContent);
        $this->assertSame('X', $xpath->query('/w:document/w:body/w:tbl[1]/w:tr[4]/w:tc[3]')->item(0)->textContent);
        $this->assertSame(1, $xpath->query('//w:r[w:t="Open"]/w:rPr/w:strike')->length);
        $this->assertSame(0, $xpath->query('//w:r[contains(w:t,"Close")]/w:rPr/w:strike')->length);
        $this->assertStringContainsString('14 Oktober 2026', $xpath->document->textContent);
        $this->assertStringContainsString('Auditor Nyata', $xpath->document->textContent);
        $this->assertStringContainsString('Atasan Nyata', $xpath->document->textContent);
        $this->assertSame('24', $xpath->query($table.'/w:tr[5]/w:tc[2]/w:p/w:r/w:rPr/w:sz')->item(0)->getAttributeNS($this->namespace(), 'val'));
    }

    public function test_blank_draft_does_not_invent_minor_dates_names_or_verification(): void
    {
        $package = $this->readPackage($this->generate($this->report([
            'nomor_laporan' => null,
            'area' => null,
            'tanggal_laporan' => null,
            'diajukan_kepada_nama' => null,
            'dilaporkan_oleh_nama' => null,
            'status_temuan' => null,
            'sumber_codes' => [],
            'auditor_nama' => null,
            'atasan_nama' => null,
        ])));
        $xpath = $this->xml($package['word/document.xml']);
        $this->assertStringNotContainsString('Minor', $xpath->document->textContent);
        $this->assertSame(0, $xpath->query('/w:document/w:body/w:tbl[2]/w:tr[8]//w:strike')->length);
        $this->assertSame('', $xpath->query('/w:document/w:body/w:tbl[2]/w:tr[5]/w:tc[1]')->item(0)->textContent);
        $this->assertSame('', $xpath->query('/w:document/w:body/w:tbl[2]/w:tr[10]/w:tc[1]/w:p[4]')->item(0)->textContent);
        $this->assertStringNotContainsString('Oktober', $xpath->document->textContent);
    }

    public function test_non_audit_report_keeps_auditor_signature_blank_and_open_strikes_close(): void
    {
        $xpath = $this->xml($this->readPackage($this->generate($this->report([
            'sumber_codes' => ['laporan_insiden'],
            'auditor_nama' => 'Bukan auditor kegiatan ini',
            'verifikasi' => 'Open',
        ])))['word/document.xml']);
        $this->assertStringNotContainsString('Bukan auditor kegiatan ini', $xpath->document->textContent);
        $this->assertSame(1, $xpath->query('//w:r[contains(w:t,"Close")]/w:rPr/w:strike')->length);
        $this->assertSame(0, $xpath->query('//w:r[w:t="Open"]/w:rPr/w:strike')->length);
    }

    public function test_each_export_has_an_isolated_private_directory(): void
    {
        $first = $this->generate($this->report());
        $second = $this->generate($this->report());
        $this->assertNotSame(dirname($first), dirname($second));
        $this->assertStringStartsWith(storage_path('app/private/fr009-exports'), $first);
        $this->assertFileExists($first);
        $this->assertFileExists($second);
    }

    public function test_pdf_converter_returns_service_unavailable_when_not_configured(): void
    {
        config(['services.fr009.pdf_converter' => null]);
        $this->expectException(ServiceUnavailableHttpException::class);
        app(DocxToPdfService::class)->assertAvailable();
    }

    public function test_pdf_converter_rejects_relative_executable_and_cleans_owned_docx_on_failure(): void
    {
        $path = $this->generate($this->report());
        config(['services.fr009.pdf_converter' => 'soffice']);
        try {
            app(DocxToPdfService::class)->convert($path);
            $this->fail('Relative converter executable was accepted.');
        } catch (ServiceUnavailableHttpException $exception) {
            $this->assertSame(503, $exception->getStatusCode());
        }
        $this->assertFileDoesNotExist($path);
        $this->assertDirectoryDoesNotExist(dirname($path).'/profile');
        $this->assertDirectoryDoesNotExist(dirname($path).'/converted');
    }

    public function test_pdf_converter_does_not_delete_an_input_outside_export_storage(): void
    {
        $template = resource_path('templates/FR-009 Form Tindakan Perbaikan dan Pencegahan.docx');
        $beforeHash = hash_file('sha256', $template);
        try {
            app(DocxToPdfService::class)->convert($template);
            $this->fail('An outside input path was accepted.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Lokasi dokumen ekspor tidak valid.', $exception->getMessage());
        }
        $this->assertSame($beforeHash, hash_file('sha256', $template));
    }

    public function test_converter_without_pdf_output_removes_source_and_profile_without_using_desktop_office(): void
    {
        $path = $this->generate($this->report());
        config(['services.fr009.pdf_converter' => PHP_BINARY]);
        try {
            app(DocxToPdfService::class)->convert($path);
            $this->fail('The PHP executable should not act as an office converter.');
        } catch (\RuntimeException $exception) {
            $this->assertNotEmpty($exception->getMessage());
        }
        $this->assertFileDoesNotExist($path);
        $this->assertFileDoesNotExist(dirname($path).'/FR-009.pdf');
        $this->assertDirectoryDoesNotExist(dirname($path).'/profile');
        $this->assertDirectoryDoesNotExist(dirname($path).'/converted');
    }

    public function test_cleanup_removes_only_expired_exporter_directories(): void
    {
        $originalStorage = $this->app->storagePath();
        $testStorage = sys_get_temp_dir().'/fr009-cleanup-'.bin2hex(random_bytes(8));
        $this->app->useStoragePath($testStorage);
        try {
            $expired = dirname($this->generate($this->report()));
            $recent = dirname($this->generate($this->report()));
            $unmanaged = storage_path('app/private/fr009-exports/keep-me');
            File::ensureDirectoryExists($unmanaged);
            touch($expired, time() - 90000);
            touch($unmanaged, time() - 90000);
            $this->artisan('smki:cleanup-fr009-exports')->assertSuccessful();
            $this->assertDirectoryDoesNotExist($expired);
            $this->assertDirectoryExists($recent);
            $this->assertDirectoryExists($unmanaged);
        } finally {
            $this->app->useStoragePath($originalStorage);
            File::deleteDirectory($testStorage);
        }
    }

    private function report(array $attributes = [], array $items = []): SmkiTindakanPerbaikan
    {
        $report = new SmkiTindakanPerbaikan;
        $report->forceFill(array_merge([
            'id' => 1,
            'nomor_laporan' => 'TPP-001/2026',
            'area' => 'Aptika',
            'tanggal_laporan' => '2026-10-14',
            'diajukan_kepada_nama' => 'Penerima',
            'dilaporkan_oleh_nama' => 'Pelapor',
            'sumber_codes' => [],
            'sumber_lainnya' => null,
            'status_temuan' => 'Minor',
            'deskripsi' => null,
            'akar_masalah' => null,
            'status' => 'draft',
            'verifikasi' => null,
            'tanggal_verifikasi' => null,
            'catatan_verifikasi' => null,
            'auditor_nama' => null,
            'atasan_nama' => null,
        ], $attributes));
        $report->setRelation('items', new Collection(array_map(fn (array $item) => (object) $item, $items)));

        return $report;
    }

    private function generate(SmkiTindakanPerbaikan $report): string
    {
        $path = app(SmkiTindakanPerbaikanDocxService::class)->generateDocx($report);
        $this->generatedPaths[] = $path;

        return $path;
    }

    private function readPackage(string $path): array
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path));
        $parts = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = $zip->getNameIndex($index);
            $parts[$name] = $zip->getFromName($name);
        }
        $zip->close();
        ksort($parts);

        return $parts;
    }

    private function xml(string $bytes): DOMXPath
    {
        $dom = new DOMDocument;
        $dom->preserveWhiteSpace = true;
        $this->assertTrue($dom->loadXML($bytes, LIBXML_NONET));
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('w', $this->namespace());

        return $xpath;
    }

    private function canonicalNodes(DOMXPath $xpath, string $expression): array
    {
        return array_map(fn ($node) => $node->C14N(), iterator_to_array($xpath->query($expression)));
    }

    private function namespace(): string
    {
        return 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    }
}
