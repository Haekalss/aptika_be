<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('smki_tindakan_perbaikans', function (Blueprint $table) {
            $table->id();
            $table->string('nomor_laporan', 100)->nullable()->unique();
            $table->unsignedBigInteger('id_unit_kerja')->nullable();
            $table->foreign('id_unit_kerja')->references('id_unit_kerja')->on('smki_unit_kerjas')->nullOnDelete();
            $table->string('area')->nullable();
            $table->date('tanggal_laporan')->nullable();
            $table->foreignId('diajukan_kepada_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('diajukan_kepada_nama')->nullable();
            $table->foreignId('dilaporkan_oleh_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('dilaporkan_oleh_nama')->nullable();
            $table->json('sumber_codes')->nullable();
            $table->text('sumber_lainnya')->nullable();
            $table->string('status_temuan', 20)->nullable();
            $table->text('deskripsi')->nullable();
            $table->text('akar_masalah')->nullable();
            $table->string('status', 20)->default('draft');
            $table->string('verifikasi', 10)->nullable();
            $table->date('tanggal_verifikasi')->nullable();
            $table->text('catatan_verifikasi')->nullable();
            $table->unsignedInteger('id_auditor')->nullable();
            $table->foreign('id_auditor')->references('id_auditor')->on('auditor')->nullOnDelete();
            $table->string('auditor_nama')->nullable();
            $table->foreignId('atasan_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('atasan_nama')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('bidang_id')->constrained('bidangs')->restrictOnDelete();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->string('template_version', 20)->default('3.0');
            $table->timestamps();
            $table->softDeletes();
            $table->index(['bidang_id', 'created_by', 'status']);
            $table->index(['bidang_id', 'verifikasi']);
        });

        Schema::create('smki_tindakan_perbaikan_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tindakan_perbaikan_id')->constrained('smki_tindakan_perbaikans')->cascadeOnDelete();
            $table->string('jenis', 30);
            $table->unsignedInteger('urutan')->default(1);
            $table->text('uraian')->nullable();
            $table->foreignId('pic_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('pic_nama')->nullable();
            $table->date('batas_waktu')->nullable();
            $table->timestamps();
            $table->index(['tindakan_perbaikan_id', 'jenis', 'urutan'], 'fr009_items_order_index');
        });

        Schema::create('smki_tindakan_perbaikan_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tindakan_perbaikan_id')
                ->constrained('smki_tindakan_perbaikans', indexName: 'fr009_verifications_parent_fk')->cascadeOnDelete();
            $table->index('tindakan_perbaikan_id', 'fr009_verifications_parent_index');
            $table->string('verifikasi', 10);
            $table->date('tanggal_verifikasi');
            $table->text('catatan_verifikasi');
            $table->string('auditor_nama')->nullable();
            $table->string('atasan_nama');
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('content_version');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('smki_tindakan_perbaikan_verifications');
        Schema::dropIfExists('smki_tindakan_perbaikan_items');
        Schema::dropIfExists('smki_tindakan_perbaikans');
    }
};
