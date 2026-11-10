<?php

namespace App\Http\Controllers\Smki;

use App\Http\Controllers\Controller;
use App\Http\Requests\Smki\StoreBackupRestoreAttachmentRequest;
use App\Http\Resources\Smki\BackupRestoreAttachmentResource;
use App\Models\SmkiBackupRestoreAttachment;
use App\Models\SmkiBackupRestoreEntry;
use App\Models\SmkiBackupRestoreReport;
use App\Services\Smki\BackupRestoreAttachmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class SmkiBackupRestoreAttachmentController extends Controller
{
    public function store(StoreBackupRestoreAttachmentRequest $request, string $report, string $entry, BackupRestoreAttachmentService $service)
    {
        $record = $this->report($request, $report);
        $activity = SmkiBackupRestoreEntry::whereHas('asset', fn ($q) => $q->where('report_id', $record->id))->findOrFail($entry);
        $file = $service->upload($record, $activity, $request->file('file'), $request->integer('version'), $request->user());

        return response()->json(['success' => true, 'data' => ['attachment' => (new BackupRestoreAttachmentResource($file))->resolve($request), 'version' => $request->integer('version') + 1]], 201);
    }

    public function show(Request $request, string $report, string $attachment)
    {
        $record = $this->report($request, $report);
        $file = $this->attachment($record, $attachment);
        abort_unless(Storage::disk('local')->exists($file->path), 404);

        return Storage::disk('local')->response($file->path, $file->original_name, ['Content-Type' => $file->mime, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function destroy(Request $request, string $report, string $attachment, BackupRestoreAttachmentService $service)
    {
        $record = $this->report($request, $report);
        $file = $this->attachment($record, $attachment);
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $service->delete($record, $file, $data['version'], $request->user());

        return response()->json(['success' => true, 'data' => ['version' => $data['version'] + 1]]);
    }

    private function report(Request $request, string $id): SmkiBackupRestoreReport
    {
        Gate::authorize('create', SmkiBackupRestoreReport::class);
        $report = SmkiBackupRestoreReport::visibleTo($request->user())->findOrFail($id);
        Gate::authorize('view', $report);

        return $report;
    }

    private function attachment(SmkiBackupRestoreReport $report, string $id): SmkiBackupRestoreAttachment
    {
        return SmkiBackupRestoreAttachment::whereHas('entry.asset', fn ($q) => $q->where('report_id', $report->id))->findOrFail($id);
    }
}
