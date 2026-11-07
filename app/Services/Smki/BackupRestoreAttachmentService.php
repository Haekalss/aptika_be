<?php

namespace App\Services\Smki;

use App\Jobs\DeleteBackupRestoreFiles;
use App\Models\SmkiBackupRestoreAttachment;
use App\Models\SmkiBackupRestoreEntry;
use App\Models\SmkiBackupRestoreReport;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

class BackupRestoreAttachmentService
{
    public function upload(SmkiBackupRestoreReport $report, SmkiBackupRestoreEntry $entry, UploadedFile $file, int $version, User $user): SmkiBackupRestoreAttachment
    {
        $path = null;
        try {
            return DB::transaction(function () use ($report, $entry, $file, $version, $user, &$path) {
                $current = $this->lock($report, $version, $user);
                $activity = SmkiBackupRestoreEntry::whereHas('asset', fn ($q) => $q->where('report_id', $current->id))->findOrFail($entry->id);
                $reportCount = SmkiBackupRestoreAttachment::whereHas('entry.asset', fn ($q) => $q->where('report_id', $current->id))->count();
                if ($activity->attachments()->count() >= 5 || $reportCount >= 50) {
                    throw ValidationException::withMessages(['file' => 'Maksimal lima bukti per kegiatan dan 50 bukti per laporan.']);
                }
                $path = $file->store('smki/backup-restore/'.$current->id, 'local');
                abort_unless(is_string($path) && $path !== '', 503, 'Penyimpanan bukti tidak tersedia.');
                $attachment = $activity->attachments()->create([
                    'uploaded_by' => $user->id, 'disk' => 'local', 'path' => $path,
                    'original_name' => $file->getClientOriginalName(), 'mime' => $file->getMimeType(), 'size' => $file->getSize(),
                ]);
                $current->forceFill(['version' => $current->version + 1, 'updated_by' => $user->id])->save();

                return $attachment;
            });
        } catch (Throwable $error) {
            if (is_string($path) && $path !== '') {
                // A failed upload has no committed owner; an outer rollback must not discard its cleanup.
                try {
                    DeleteBackupRestoreFiles::dispatchSync([$path]);
                } catch (Throwable $cleanupError) {
                    report($cleanupError);
                }
            }
            throw $error;
        }
    }

    public function delete(SmkiBackupRestoreReport $report, SmkiBackupRestoreAttachment $attachment, int $version, User $user): void
    {
        DB::transaction(function () use ($report, $attachment, $version, $user) {
            $current = $this->lock($report, $version, $user);
            $file = SmkiBackupRestoreAttachment::whereHas('entry.asset', fn ($q) => $q->where('report_id', $current->id))->findOrFail($attachment->id);
            $path = $file->path;
            $file->delete();
            $current->forceFill(['version' => $current->version + 1, 'updated_by' => $user->id])->save();
            self::cleanupAfterCommit([$path]);
        });
    }

    public static function cleanupAfterCommit(array $paths): void
    {
        if ($paths !== []) {
            DB::afterCommit(fn () => DeleteBackupRestoreFiles::dispatch($paths));
        }
    }

    private function lock(SmkiBackupRestoreReport $report, int $version, User $user): SmkiBackupRestoreReport
    {
        Gate::forUser($user)->authorize('update', $report);
        $current = SmkiBackupRestoreReport::visibleTo($user)->lockForUpdate()->findOrFail($report->id);
        abort_if($current->version !== $version, 409, 'Dokumen sudah berubah. Muat ulang sebelum mengubah bukti.');

        return $current;
    }
}
