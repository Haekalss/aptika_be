<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('smki:cleanup-fr009-exports', function () {
    $root = storage_path('app/private/fr009-exports');
    if (! is_dir($root) || is_link($root)) {
        $this->info('Tidak ada ekspor sementara FR-009.');

        return;
    }
    $resolvedRoot = realpath($root);
    if ($resolvedRoot === false) {
        $this->error('Lokasi ekspor sementara tidak dapat diverifikasi.');

        return;
    }
    foreach (File::directories($root) as $directory) {
        // Only exporter-owned UUID directories; never follow a symbolic link.
        $resolved = realpath($directory);
        if ($resolved && str_starts_with($resolved, $resolvedRoot.DIRECTORY_SEPARATOR)
            && ! is_link($directory) && preg_match('/^[0-9a-f-]{36}$/i', basename($directory))
            && filemtime($directory) < time() - 86400) {
            File::deleteDirectory($directory);
        }
    }
    $this->info('Ekspor sementara FR-009 lebih dari 24 jam dibersihkan.');
})->purpose('Hapus direktori privat ekspor FR-009 yang sudah kedaluwarsa');

Schedule::command('smki:cleanup-fr009-exports')->daily()->withoutOverlapping();
