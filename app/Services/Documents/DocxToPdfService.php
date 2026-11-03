<?php

namespace App\Services\Documents;

use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\Process\Process;
use Throwable;

class DocxToPdfService
{
    public function assertAvailable(): string
    {
        $configured = config('services.fr009.pdf_converter');
        if (! is_string($configured) || ! preg_match('#^(?:[A-Za-z]:[\\\\/]|/)#', $configured)) {
            throw new ServiceUnavailableHttpException(null, 'Ekspor PDF belum tersedia: converter server belum dikonfigurasi.');
        }
        $executable = realpath($configured);
        if ($executable === false || ! is_file($executable) || ! is_executable($executable)) {
            throw new ServiceUnavailableHttpException(null, 'Ekspor PDF belum tersedia: converter server tidak dapat dijalankan.');
        }

        return $executable;
    }

    public function convert(string $docxPath): string
    {
        $root = realpath(storage_path('app/private/fr009-exports'));
        $source = realpath($docxPath);
        if ($root === false || $source === false || ! str_starts_with($source, $root.DIRECTORY_SEPARATOR)
            || strtolower(pathinfo($source, PATHINFO_EXTENSION)) !== 'docx') {
            throw new RuntimeException('Lokasi dokumen ekspor tidak valid.');
        }

        $directory = dirname($source);
        $profile = $directory.'/profile';
        $converted = $directory.'/converted';
        $pdfPath = $directory.'/'.pathinfo($source, PATHINFO_FILENAME).'.pdf';
        try {
            $executable = $this->assertAvailable();
            File::ensureDirectoryExists($profile, 0700);
            File::ensureDirectoryExists($converted, 0700);
            $process = new Process([
                $executable,
                '-env:UserInstallation='.$this->fileUri($profile),
                '--headless',
                '--convert-to',
                'pdf:writer_pdf_Export',
                '--outdir',
                $converted,
                $source,
            ], $directory);
            $process->setTimeout(max(1, (int) config('services.fr009.pdf_timeout', 60)));
            $process->mustRun();
            $convertedPdf = $converted.'/'.basename($pdfPath);
            $handle = is_file($convertedPdf) ? fopen($convertedPdf, 'rb') : false;
            $magic = $handle ? fread($handle, 5) : false;
            if ($handle) {
                fclose($handle);
            }
            if ($magic !== '%PDF-' || filesize($convertedPdf) <= 5 || ! rename($convertedPdf, $pdfPath)) {
                throw new RuntimeException('Converter tidak menghasilkan berkas PDF yang valid.');
            }

            return $pdfPath;
        } catch (Throwable $exception) {
            File::delete($pdfPath);
            throw $exception;
        } finally {
            File::delete($source);
            File::deleteDirectory($profile);
            File::deleteDirectory($converted);
        }
    }

    private function fileUri(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $segments = array_map('rawurlencode', explode('/', $path));
        if (preg_match('/^[A-Za-z]:\//', $path)) {
            $segments[0] = substr($path, 0, 2);

            return 'file:///'.implode('/', $segments);
        }

        return 'file://'.implode('/', $segments);
    }
}
