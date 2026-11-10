<?php

namespace Tests\Feature\Smki;

use Illuminate\Support\Facades\DB;
use Tests\Support\BackupRestoreTestCase;

class BackupRestoreReportTest extends BackupRestoreTestCase
{
    public function test_zero_note_is_meaningful_in_draft_and_requires_completion_for_final(): void
    {
        $this->actingAs($this->account(), 'sanctum');
        $data = $this->payload();
        $data['assets'][0]['entries'][0] = ['month' => 10, 'operation' => 'backup', 'result' => null, 'performed_on' => null, 'notes' => '0'];
        $this->postJson('/api/smki/backup-restore', $data)->assertCreated()->assertJsonPath('data.assets.0.entries.0.notes', '0');
        $data['finalize'] = true;
        $data['assets'][0]['entries'][] = ['month' => 10, 'operation' => 'restore', 'result' => 'berhasil', 'performed_on' => '2026-10-04'];
        $this->postJson('/api/smki/backup-restore', $data)->assertUnprocessable()->assertJsonValidationErrors('assets.0.entries.0.performed_on');
    }

    public function test_final_validates_canonical_master_identity_not_forged_display_values(): void
    {
        $user = $this->account();
        $this->actingAs($user, 'sanctum');
        DB::table('pegawai')->where('id', 2)->update(['nip' => '']);
        $this->postJson('/api/smki/backup-restore', $this->payload(['finalize' => true]))
            ->assertUnprocessable()->assertJsonValidationErrors('pelaksana_pegawai_id');
        DB::table('pegawai')->where('id', 2)->update(['nip' => '002']);
        DB::table('daftar_aset_tis')->insert(['id' => 9, 'bidang_id' => $user->bidang_id, 'nama_aset' => null]);
        $data = $this->payload(['finalize' => true]);
        $data['assets'][0]['aset_ti_id'] = 9;
        $this->postJson('/api/smki/backup-restore', $data)->assertUnprocessable()->assertJsonValidationErrors('assets.0.nama_aset');
        $data['assets'][0]['aset_ti_id'] = 0;
        $this->postJson('/api/smki/backup-restore', $data)->assertUnprocessable()->assertJsonValidationErrors('assets.0.aset_ti_id');
        $this->assertSame(0, DB::table('smki_backup_restore_reports')->count());
    }

    public function test_updating_many_assets_does_not_add_one_read_query_per_row(): void
    {
        $this->actingAs($this->account(), 'sanctum');
        $reads = [];
        foreach ([1, 20] as $count) {
            $data = $this->payload();
            $row = $data['assets'][0];
            $data['assets'] = [];
            for ($index = 0; $index < $count; $index++) {
                $row['client_key'] = sprintf('48c85cb0-dc80-4ef0-a134-%012d', $index);
                $data['assets'][] = $row;
            }
            $created = $this->postJson('/api/smki/backup-restore', $data)->assertCreated()->json('data');
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->putJson('/api/smki/backup-restore/'.$created['id'], $this->payload(['version' => 1, 'assets' => $this->stripAttachments($created['assets'])]))->assertOk();
            $reads[] = count(array_filter(DB::getQueryLog(), fn ($q) => str_starts_with(strtolower($q['query']), 'select')));
            DB::disableQueryLog();
        }
        $this->assertLessThanOrEqual($reads[0] + 3, $reads[1], 'Reads should be batched, not proportional to asset count.');
    }

    public function test_same_bidang_crud_preserves_ids_snapshots_final_and_versions(): void
    {
        $owner = $this->account();
        $editor = $this->account();
        $this->actingAs($owner, 'sanctum');
        $created = $this->postJson('/api/smki/backup-restore', $this->payload())->assertCreated()->assertJsonPath('data.pelaksana_nama', 'Budi Santoso')->json('data');
        $id = $created['id'];
        $this->actingAs($editor, 'sanctum');
        DB::table('pegawai')->where('id', 2)->update(['nama' => 'Nama baru']);
        $data = $this->payload(['version' => 1, 'finalize' => true, 'assets' => $this->stripAttachments($created['assets'])]);
        $updated = $this->putJson("/api/smki/backup-restore/$id", $data)->assertOk()
            ->assertJsonPath('data.status', 'final')->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.pelaksana_nama', 'Budi Santoso')->assertJsonPath('data.permissions.can_delete', true)->json('data');
        $this->assertSame($created['assets'][0]['entries'][0]['id'], $updated['assets'][0]['entries'][0]['id']);
        $this->putJson("/api/smki/backup-restore/$id", $data)->assertStatus(409);
        $this->putJson("/api/smki/backup-restore/$id", array_replace($data, ['version' => 2, 'finalize' => false]))->assertOk()->assertJsonPath('data.status', 'final');
        $this->getJson('/api/smki/backup-restore?year=2026&search=Switch')->assertOk()->assertJsonPath('data.total', 1);
        $this->deleteJson("/api/smki/backup-restore/$id", ['version' => 2])->assertStatus(409);
        $this->deleteJson("/api/smki/backup-restore/$id", ['version' => 3])->assertOk();
        $this->getJson("/api/smki/backup-restore/$id")->assertNotFound();
    }

    public function test_admin_aptika_and_users_cannot_access_other_bidang(): void
    {
        $this->actingAs($this->account(), 'sanctum');
        $id = $this->postJson('/api/smki/backup-restore', $this->payload())->assertCreated()->json('data.id');
        foreach (['admin', 'user'] as $role) {
            $this->actingAs($this->account('APTIKA', $role), 'sanctum');
            $this->getJson("/api/smki/backup-restore/$id")->assertNotFound();
            $this->putJson("/api/smki/backup-restore/$id", $this->payload(['version' => 1]))->assertNotFound();
            $this->deleteJson("/api/smki/backup-restore/$id", ['version' => 1])->assertNotFound();
            $this->getJson('/api/smki/backup-restore')->assertOk()->assertJsonPath('data.total', 0);
        }
    }

    public function test_partial_draft_and_final_validation_do_not_invent_results(): void
    {
        $this->actingAs($this->account(), 'sanctum');
        $data = $this->payload(['pelaksana_pegawai_id' => null, 'penanggung_jawab_pegawai_id' => null]);
        $data['assets'][0]['entries'][0]['performed_on'] = null;
        $this->postJson('/api/smki/backup-restore', $data)->assertCreated()->assertJsonPath('data.assets.0.entries.0.performed_on', null);
        $this->postJson('/api/smki/backup-restore', array_replace($data, ['finalize' => true]))->assertUnprocessable();
        $this->postJson('/api/smki/backup-restore', $this->payload(['finalize' => true]))->assertCreated()->assertJsonPath('data.status', 'final');
        $data = $this->payload();
        $data['assets'][0]['entries'][0]['performed_on'] = '2026-09-03';
        $this->postJson('/api/smki/backup-restore', $data)->assertUnprocessable()->assertJsonValidationErrors('assets.0.entries.0.performed_on');
    }

    public function test_foreign_child_and_duplicate_operations_are_rejected(): void
    {
        $this->actingAs($this->account(), 'sanctum');
        $a = $this->postJson('/api/smki/backup-restore', $this->payload())->assertCreated()->json('data');
        $b = $this->postJson('/api/smki/backup-restore', $this->payload())->assertCreated()->json('data');
        $data = $this->payload(['version' => 1]);
        $data['assets'][0]['id'] = $a['assets'][0]['id'];
        $this->putJson('/api/smki/backup-restore/'.$b['id'], $data)->assertUnprocessable();
        $data = $this->payload();
        $data['assets'][0]['entries'][] = $data['assets'][0]['entries'][0];
        $this->postJson('/api/smki/backup-restore', $data)->assertUnprocessable();
        $this->postJson('/api/smki/backup-restore', $this->payload(['bidang_id' => 999]))->assertUnprocessable();
    }

    public function test_lookup_and_master_references_preserve_source_access(): void
    {
        $user = $this->account();
        $other = $this->account();
        $foreign = $this->account('OTHER');
        DB::table('daftar_aset_tis')->insert([
            ['id' => 1, 'bidang_id' => $user->bidang_id, 'nama_aset' => 'Router asli', 'kode' => 'RT1'],
            ['id' => 2, 'bidang_id' => $foreign->bidang_id, 'nama_aset' => 'Rahasia', 'kode' => 'RT2'],
        ]);
        DB::table('smki_daftar_aset_virtuals')->insert(['id' => 1, 'bidang_id' => $user->bidang_id, 'created_by' => $other->id]);
        DB::table('smki_daftar_aset_virtual_instances')->insert(['id' => 1, 'daftar_aset_virtual_id' => 1, 'nama_instance' => 'Private VS']);
        $this->actingAs($user, 'sanctum');
        $this->getJson('/api/smki/backup-restore/lookup?asset_type=physical')->assertOk()->assertJsonCount(1, 'data.assets.data')->assertJsonPath('data.assets.data.0.nama_aset', 'Router asli');
        $this->getJson('/api/smki/backup-restore/lookup?asset_type=virtual')->assertOk()->assertJsonPath('data.assets.total', 0);
        $data = $this->payload();
        $data['assets'][0]['aset_ti_id'] = 2;
        $this->postJson('/api/smki/backup-restore', $data)->assertUnprocessable();
        $data['assets'][0]['aset_ti_id'] = 1;
        $this->postJson('/api/smki/backup-restore', $data)->assertCreated()->assertJsonPath('data.assets.0.nama_aset', 'Router asli');
        $data['assets'][0]['virtual_instance_id'] = 1;
        $this->postJson('/api/smki/backup-restore', $data)->assertUnprocessable();
    }
}
