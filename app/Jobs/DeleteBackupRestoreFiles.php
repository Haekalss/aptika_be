<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class DeleteBackupRestoreFiles implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public array $paths) {}

    public function handle(): void
    {
        foreach (array_unique($this->paths) as $path) {
            if (! is_string($path) || str_contains($path, '..') || ! preg_match('~^smki/backup-restore/[1-9][0-9]*/[A-Za-z0-9._-]+$~D', $path)) {
                continue;
            }
            if (Storage::disk('local')->exists($path) && ! Storage::disk('local')->delete($path)) {
                throw new RuntimeException('Gagal membersihkan file bukti FR-030.');
            }
        }
    }
}
