<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('smki_removable_media_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bidang_id')->constrained('bidangs')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('created_by_nama');
            $table->string('unit_kerja_nama');
            $table->date('tanggal_dokumen')->nullable();
            $table->string('tempat', 100)->nullable();
            $table->foreignId('pengurus_barang_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('pengurus_barang_nama')->nullable();
            $table->foreignId('kepala_unit_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kepala_unit_nama')->nullable();
            $table->string('status', 10)->default('draft');
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('finalized_at')->nullable();
            $table->string('template_version', 10)->default('1.0');
            $table->timestamps();
            $table->softDeletes();
            $table->index(['bidang_id', 'created_by', 'status'], 'fr025_access_status_idx');
        });

        Schema::create('smki_removable_media_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_id')->constrained('smki_removable_media_reports')->cascadeOnDelete();
            $table->unsignedInteger('urutan');
            $table->foreignId('nama_barang_id')->nullable()->constrained('aset_ti_namas')->nullOnDelete();
            $table->string('nama_barang')->nullable();
            $table->foreignId('merek_id')->nullable()->constrained('aset_ti_mereks')->nullOnDelete();
            $table->string('merek')->nullable();
            $table->unsignedInteger('jumlah')->nullable();
            $table->unsignedSmallInteger('tahun_pengadaan')->nullable();
            $table->foreignId('pic_id')->nullable()->constrained('aset_ti_penanggung_jawabs')->nullOnDelete();
            $table->string('pic_nama')->nullable();
            $table->string('kondisi', 20)->nullable();
            $table->text('keterangan')->nullable();
            $table->timestamps();
            $table->unique(['report_id', 'urutan']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('smki_removable_media_items');
        Schema::dropIfExists('smki_removable_media_reports');
    }
};
