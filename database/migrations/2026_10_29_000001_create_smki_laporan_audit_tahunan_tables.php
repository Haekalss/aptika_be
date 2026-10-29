<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('smki_laporan_audit_tahunans')) {
            Schema::create('smki_laporan_audit_tahunans', function (Blueprint $table) {
                $table->id();
                $table->unsignedSmallInteger('tahun_smki')->index();
                $table->string('no_dokumen', 100);
                $table->string('no_revisi', 50)->default('1.0');
                $table->date('tanggal_berlaku')->nullable();
                $table->text('kriteria_audit')->nullable();
                $table->text('tujuan')->nullable();
                $table->string('disusun_oleh')->nullable();
                $table->string('jabatan_penyusun')->nullable();
                $table->string('disahkan_oleh')->nullable();
                $table->string('jabatan_pengesah')->nullable();
                $table->string('status', 30)->default('Draft')->index();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (!Schema::hasTable('smki_laporan_audit_tahunan_tims')) {
            Schema::create('smki_laporan_audit_tahunan_tims', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('laporan_id')->index();
                $table->string('nama_auditor');
                $table->string('unit_kerja')->nullable();
                $table->string('peran', 50)->default('Auditor');
                $table->timestamps();
                $table->foreign('laporan_id')->references('id')->on('smki_laporan_audit_tahunans')->cascadeOnDelete();
            });
        }

        if (!Schema::hasTable('smki_laporan_audit_tahunan_objeks')) {
            Schema::create('smki_laporan_audit_tahunan_objeks', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('laporan_id')->index();
                $table->string('kode_objek', 50)->nullable();
                $table->string('objek_audit');
                $table->text('ruang_lingkup')->nullable();
                $table->string('unit_kerja')->nullable();
                $table->timestamps();
                $table->foreign('laporan_id')->references('id')->on('smki_laporan_audit_tahunans')->cascadeOnDelete();
            });
        }

        if (!Schema::hasTable('smki_laporan_audit_tahunan_klausuls')) {
            Schema::create('smki_laporan_audit_tahunan_klausuls', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('objek_id')->index();
                $table->string('klausul_annex');
                $table->text('uraian_klausul')->nullable();
                $table->timestamps();
                $table->foreign('objek_id')->references('id')->on('smki_laporan_audit_tahunan_objeks')->cascadeOnDelete();
            });
        }

        if (!Schema::hasTable('smki_laporan_audit_tahunan_jadwals')) {
            Schema::create('smki_laporan_audit_tahunan_jadwals', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('objek_id')->index();
                $table->unsignedTinyInteger('bulan');
                $table->timestamps();
                $table->foreign('objek_id')->references('id')->on('smki_laporan_audit_tahunan_objeks')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('smki_laporan_audit_tahunan_jadwals');
        Schema::dropIfExists('smki_laporan_audit_tahunan_klausuls');
        Schema::dropIfExists('smki_laporan_audit_tahunan_objeks');
        Schema::dropIfExists('smki_laporan_audit_tahunan_tims');
        Schema::dropIfExists('smki_laporan_audit_tahunans');
    }
};

