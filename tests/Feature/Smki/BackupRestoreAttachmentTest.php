<?php

namespace Tests\Feature\Smki;

use App\Jobs\DeleteBackupRestoreFiles;
use App\Models\SmkiBackupRestoreAttachment;
use App\Models\SmkiBackupRestoreReport;
use App\Services\Smki\BackupRestoreAttachmentService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Support\BackupRestoreTestCase;

class BackupRestoreAttachmentTest extends BackupRestoreTestCase
{
    public function test_file_responses_return_their_own_committed_version_not_a_later_writers_version(): void
    {
        $record = $this->createReport();
        $real = new BackupRestoreAttachmentService;
        $this->mock(BackupRestoreAttachmentService::class)->shouldReceive('upload')->andReturnUsing(function ($report, $entry, $file, $version, $user) use ($real) {
            $attachment = $real->upload($report, $entry, $file, $version, $user);
            DB::table('smki_backup_restore_reports')->where('id', $report->id)->update(['version' => 3, 'nomor_dokumen' => 'Other writer']);

            return $attachment;
        });
        $attachment = $this->upload($record)->assertCreated()->assertJsonPath('data.version', 2)->json('data.attachment.id');
        $this->putJson('/api/smki/backup-restore/'.$record['id'], $this->payload(['version' => 2]))->assertStatus(409);
        $this->assertSame('Other writer', SmkiBackupRestoreReport::findOrFail($record['id'])->nomor_dokumen);
        $this->mock(BackupRestoreAttachmentService::class)->shouldReceive('delete')->andReturnUsing(function ($report, $file, $version, $user) use ($real) {
            $real->delete($report, $file, $version, $user);
            DB::table('smki_backup_restore_reports')->where('id', $report->id)->update(['version' => 5]);
        });
        $this->deleteJson('/api/smki/backup-restore/'.$record['id'].'/attachments/'.$attachment, ['version' => 3])->assertOk()->assertJsonPath('data.version', 4);
    }

    public function test_final_checks_resolved_evidence_entry_when_payload_omits_entry_id(): void
    {
        Storage::fake('local');
        $this->actingAs($this->account(), 'sanctum');
        $record = $this->postJson('/api/smki/backup-restore', $this->payload())->assertCreated()->json('data');
        $this->post('/api/smki/backup-restore/'.$record['id'].'/entries/'.$record['assets'][0]['entries'][0]['id'].'/attachments', ['file' => UploadedFile::fake()->image('proof.png'), 'version' => 1], ['Accept' => 'application/json'])->assertCreated();
        $data = $this->payload(['version' => 2, 'finalize' => true]);
        $data['assets'][0]['id'] = $record['assets'][0]['id'];
        $data['assets'][0]['entries'] = [
            ['month' => 10, 'operation' => 'backup', 'result' => null, 'performed_on' => null, 'notes' => null],
            ['month' => 10, 'operation' => 'restore', 'result' => 'berhasil', 'performed_on' => '2026-10-04'],
        ];
        $this->putJson('/api/smki/backup-restore/'.$record['id'], $data)->assertUnprocessable()->assertJsonValidationErrors('assets.0.entries.0.performed_on');
        $this->getJson('/api/smki/backup-restore/'.$record['id'])->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.status', 'draft')->assertJsonPath('data.assets.0.entries.0.result', 'berhasil');
    }

    private function createReport(): array
    {
        Storage::fake('local');
        $this->actingAs($this->account(), 'sanctum');

        return $this->postJson('/api/smki/backup-restore', $this->payload())->assertCreated()->json('data');
    }

    private function uploadUrl(array $report): string
    {
        return '/api/smki/backup-restore/'.$report['id'].'/entries/'.$report['assets'][0]['entries'][0]['id'].'/attachments';
    }

    private function upload(array $report, int $version = 1)
    {
        return $this->post($this->uploadUrl($report), ['file' => UploadedFile::fake()->image('bukti.png', 32, 24), 'version' => $version], ['Accept' => 'application/json']);
    }

    public function test_private_upload_download_versions_and_delete(): void
    {
        $report = $this->createReport();
        $upload = $this->upload($report)->assertCreated()->assertJsonPath('data.version', 2);
        $attachment = $upload->json('data.attachment');
        $this->assertArrayNotHasKey('path', $attachment);
        $this->assertArrayNotHasKey('disk', $attachment);
        $row = SmkiBackupRestoreAttachment::findOrFail($attachment['id']);
        Storage::disk('local')->assertExists($row->path);
        $url = '/api/smki/backup-restore/'.$report['id'].'/attachments/'.$row->id;
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->upload($report, 1)->assertStatus(409);
        $this->assertCount(1, Storage::disk('local')->allFiles());
        $this->deleteJson($url, ['version' => 1])->assertStatus(409);
        $this->deleteJson($url, ['version' => 2])->assertOk()->assertJsonPath('data.version', 3);
        $this->get($url)->assertNotFound();
        Storage::disk('local')->assertMissing($row->path);
    }

    public function test_cross_report_and_cross_bidang_evidence_are_hidden(): void
    {
        $report = $this->createReport();
        $fileId = $this->upload($report)->assertCreated()->json('data.attachment.id');
        $other = $this->postJson('/api/smki/backup-restore', $this->payload())->assertCreated()->json('data');
        $foreignUrl = '/api/smki/backup-restore/'.$other['id'].'/attachments/'.$fileId;
        $this->get($foreignUrl)->assertNotFound();
        $this->deleteJson($foreignUrl, ['version' => 1])->assertNotFound();
        $this->post('/api/smki/backup-restore/'.$other['id'].'/entries/'.$report['assets'][0]['entries'][0]['id'].'/attachments', ['file' => UploadedFile::fake()->image('x.png'), 'version' => 1], ['Accept' => 'application/json'])->assertNotFound();
        $this->actingAs($this->account('OTHER', 'admin'), 'sanctum');
        $url = '/api/smki/backup-restore/'.$report['id'].'/attachments/'.$fileId;
        $this->get($url)->assertNotFound();
        $this->deleteJson($url, ['version' => 2])->assertNotFound();
        $this->upload($report, 2)->assertNotFound();
        $this->assertSame(1, SmkiBackupRestoreAttachment::count());
    }

    public function test_upload_validates_content_size_dimensions_and_count(): void
    {
        $report = $this->createReport();
        foreach ([UploadedFile::fake()->create('not-an-image.png', 5, 'text/plain'), UploadedFile::fake()->image('large.png')->size(5121), UploadedFile::fake()->image('wide.png', 6001, 1)] as $file) {
            $this->post($this->uploadUrl($report), ['file' => $file, 'version' => 1], ['Accept' => 'application/json'])->assertUnprocessable();
        }
        $this->assertCount(0, Storage::disk('local')->allFiles());
        for ($version = 1; $version <= 5; $version++) {
            $this->upload($report, $version)->assertCreated();
        }
        $this->upload($report, 6)->assertUnprocessable();
        $this->assertSame(5, SmkiBackupRestoreAttachment::count());
        $this->assertSame(6, SmkiBackupRestoreReport::find($report['id'])->version);
    }

    public function test_soft_delete_retains_files_but_blocks_access(): void
    {
        $report = $this->createReport();
        $fileId = $this->upload($report)->assertCreated()->json('data.attachment.id');
        $file = SmkiBackupRestoreAttachment::findOrFail($fileId);
        $this->deleteJson('/api/smki/backup-restore/'.$report['id'], ['version' => 2])->assertOk();
        $this->get('/api/smki/backup-restore/'.$report['id'].'/attachments/'.$fileId)->assertNotFound();
        Storage::disk('local')->assertExists($file->path);
    }

    public function test_removing_asset_cleans_evidence_only_after_commit(): void
    {
        $report = $this->createReport();
        $fileId = $this->upload($report)->assertCreated()->json('data.attachment.id');
        $file = SmkiBackupRestoreAttachment::findOrFail($fileId);
        Queue::fake();
        DB::beginTransaction();
        $this->putJson('/api/smki/backup-restore/'.$report['id'], $this->payload(['version' => 2, 'assets' => []]))->assertOk();
        Queue::assertNothingPushed();
        Storage::disk('local')->assertExists($file->path);
        DB::rollBack();
        $this->assertNotNull(SmkiBackupRestoreAttachment::find($fileId));
        $this->putJson('/api/smki/backup-restore/'.$report['id'], $this->payload(['version' => 2, 'assets' => []]))->assertOk();
        $this->assertNull(SmkiBackupRestoreAttachment::find($fileId));
        Queue::assertPushed(DeleteBackupRestoreFiles::class, function ($job) use ($file) {
            $job->handle();
            Storage::disk('local')->assertMissing($file->path);

            return true;
        });
    }

    public function test_cleanup_job_refuses_paths_outside_fr030(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('unrelated/keep.png', 'keep');
        $job = new DeleteBackupRestoreFiles(['unrelated/keep.png', 'smki/backup-restore/1/../../../unrelated/keep.png']);
        $job->handle();
        Storage::disk('local')->assertExists('unrelated/keep.png');
    }

    public function test_failed_upload_removes_new_file_even_if_outer_transaction_rolls_back(): void
    {
        $data = $this->createReport();
        $report = SmkiBackupRestoreReport::findOrFail($data['id']);
        $entry = $report->assets()->first()->entries()->first();
        SmkiBackupRestoreAttachment::creating(fn () => throw new RuntimeException('Simulated database failure'));
        DB::beginTransaction();
        try {
            app(BackupRestoreAttachmentService::class)->upload($report, $entry, UploadedFile::fake()->image('rollback.png'), 1, auth()->user());
            $this->fail('Database failure should propagate.');
        } catch (RuntimeException $error) {
            $this->assertSame('Simulated database failure', $error->getMessage());
        } finally {
            DB::rollBack();
        }
        $this->assertCount(0, Storage::disk('local')->allFiles());
        $this->assertSame(0, SmkiBackupRestoreAttachment::count());
        $this->assertSame(1, $report->fresh()->version);
    }

    public function test_storage_failure_does_not_persist_attachment_or_increment_version(): void
    {
        $report = $this->createReport();
        $disk = Storage::disk('local');
        $failedDisk = \Mockery::mock($disk);
        $failedDisk->shouldReceive('putFileAs')->once()->andReturn(false);
        Storage::set('local', $failedDisk);
        $this->upload($report)->assertStatus(503);
        $this->assertSame(0, SmkiBackupRestoreAttachment::count());
        $this->assertSame(1, SmkiBackupRestoreReport::findOrFail($report['id'])->version);
        Storage::set('local', $disk);
        Storage::disk('local')->assertDirectoryEmpty('smki/backup-restore');
    }

    public function test_report_attachment_limit_applies_across_different_entries(): void
    {
        $report = $this->createReport();
        $assetId = $report['assets'][0]['id'];
        for ($month = 1; $month <= 10; $month++) {
            $entryId = DB::table('smki_backup_restore_entries')->insertGetId([
                'asset_id' => $assetId, 'month' => $month, 'operation' => 'restore',
            ]);
            for ($file = 1; $file <= 5; $file++) {
                DB::table('smki_backup_restore_attachments')->insert([
                    'entry_id' => $entryId, 'disk' => 'local', 'path' => 'smki/backup-restore/'.$report['id'].'/'.$month.'-'.$file.'.png',
                    'original_name' => 'fixture.png', 'mime' => 'image/png', 'size' => 10,
                ]);
            }
        }
        $this->upload($report)->assertUnprocessable();
        $this->assertSame(50, SmkiBackupRestoreAttachment::count());
        $this->assertSame(1, SmkiBackupRestoreReport::findOrFail($report['id'])->version);
    }
}
