<?php

namespace App\Http\Controllers\Smki;

use App\Http\Controllers\Controller;
use App\Models\SmkiBackupRestoreReport;
use App\Services\Smki\BackupRestoreSpecimenService;
use App\Services\Smki\SmkiBackupRestoreDocxService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class SmkiBackupRestoreExportController extends Controller
{
    private function find(Request $request, string $id): SmkiBackupRestoreReport
    {
        Gate::authorize('create', SmkiBackupRestoreReport::class);
        $report = SmkiBackupRestoreReport::visibleTo($request->user())->findOrFail($id);
        Gate::authorize('export', $report);

        return $report;
    }

    public function specimen(Request $request, string $report, string $role, BackupRestoreSpecimenService $service)
    {
        $path = $service->imagePath($this->find($request, $report), $role);
        abort_unless($path, 404, 'Pengesah belum dipilih.');

        return response()->file($path, ['Content-Type' => 'image/png', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function docx(Request $request, string $report, SmkiBackupRestoreDocxService $service)
    {
        $record = $this->find($request, $report);
        $path = $service->generateDocx($record);

        return response()->download($path, 'FR-030-'.$record->tahun.'-'.$record->id.'.docx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'Cache-Control' => 'private, no-store',
        ])->deleteFileAfterSend(true);
    }
}
