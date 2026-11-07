<?php

namespace App\Services\Smki;

use App\Models\SmkiBackupRestoreReport;
use App\Services\TteSpecimenService;

class BackupRestoreSpecimenService
{
    public function __construct(private readonly TteSpecimenService $renderer) {}

    public function imagePath(SmkiBackupRestoreReport $report, string $role): ?string
    {
        abort_unless(in_array($role, ['pelaksana', 'penanggung-jawab'], true), 404);
        $prefix = str_replace('-', '_', $role);
        if (! $report->{$prefix.'_nama'}) {
            return null;
        }
        $snapshot = [];
        foreach (['nama', 'nip', 'jabatan', 'pangkat'] as $field) {
            $snapshot[$field] = $report->{$prefix.'_'.$field};
        }
        $path = $this->renderer->generate($snapshot);
        abort_unless($path && is_file($path), 503, 'Generator specimen belum tersedia. Periksa GD, font dan direktori cache server.');

        return $path;
    }
}
