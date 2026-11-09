<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('smki_backup_restore_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bidang_id')->constrained('bidangs')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('unit_kerja_nama');
            $table->unsignedSmallInteger('tahun');
            $table->string('nomor_dokumen')->nullable();
            $table->string('nomor_revisi', 30)->nullable();
            $table->date('tanggal_terbit')->nullable();
            $table->string('status', 20)->default('draft');
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('finalized_at')->nullable();
            foreach (['pelaksana', 'penanggung_jawab'] as $role) {
                $table->foreignId($role.'_pegawai_id')->nullable()->constrained('pegawai')->nullOnDelete();
                $table->string($role.'_nama')->nullable();
                $table->string($role.'_nip', 50)->nullable();
                $table->string($role.'_jabatan')->nullable();
                $table->string($role.'_pangkat')->nullable();
            }
            $table->timestamps();
            $table->softDeletes();
            $table->index(['bidang_id', 'tahun', 'status', 'updated_at'], 'fr030_report_list_idx');
        });
        Schema::create('smki_backup_restore_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_id')->constrained('smki_backup_restore_reports')->cascadeOnDelete();
            $table->uuid('client_key');
            $table->unsignedInteger('urutan');
            $table->foreignId('aset_ti_id')->nullable()->constrained('daftar_aset_tis')->nullOnDelete();
            $table->foreignId('virtual_instance_id')->nullable()->constrained('smki_daftar_aset_virtual_instances')->nullOnDelete();
            $table->string('kode_aset')->nullable();
            $table->string('nama_aset')->nullable();
            $table->timestamps();
            $table->unique(['report_id', 'client_key'], 'fr030_asset_key_unique');
            $table->index(['report_id', 'urutan'], 'fr030_asset_order_idx');
        });
        Schema::create('smki_backup_restore_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained('smki_backup_restore_assets')->cascadeOnDelete();
            $table->unsignedTinyInteger('month');
            $table->string('operation', 10);
            $table->string('result', 10)->nullable();
            $table->date('performed_on')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['asset_id', 'month', 'operation'], 'fr030_entry_operation_unique');
        });
        Schema::create('smki_backup_restore_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entry_id')->constrained('smki_backup_restore_entries')->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('disk', 20);
            $table->string('path', 500);
            $table->string('original_name');
            $table->string('mime', 100);
            $table->unsignedBigInteger('size');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('smki_backup_restore_attachments');
        Schema::dropIfExists('smki_backup_restore_entries');
        Schema::dropIfExists('smki_backup_restore_assets');
        Schema::dropIfExists('smki_backup_restore_reports');
    }
};
