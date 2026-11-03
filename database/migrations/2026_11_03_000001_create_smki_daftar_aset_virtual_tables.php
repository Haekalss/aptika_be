<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('smki_daftar_aset_virtuals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bidang_id')->constrained('bidangs')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('unit_kerja_nama');
            $table->string('created_by_nama');
            $table->string('no_dokumen')->nullable();
            $table->string('no_revisi', 30)->nullable();
            $table->string('tanggal_berlaku', 30)->nullable();
            $table->string('kode_aset')->nullable()->index();
            $table->string('nama_aset')->nullable();
            $table->string('lokasi_aset')->nullable();
            $table->string('status', 20)->default('Draf')->index();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['bidang_id', 'created_by', 'status'], 'fr035_access_status_idx');
        });

        Schema::create('smki_daftar_aset_virtual_instances', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('daftar_aset_virtual_id');
            $table->foreign('daftar_aset_virtual_id', 'fr035_instance_parent_fk')
                ->references('id')
                ->on('smki_daftar_aset_virtuals')
                ->cascadeOnDelete();
            $table->unsignedInteger('urutan');
            $table->string('kode_aset')->nullable();
            $table->string('nama_instance')->nullable();
            $table->text('deskripsi')->nullable();
            $table->string('tipe_virtualisasi')->nullable();
            $table->string('alamat')->nullable();
            $table->string('hostname')->nullable();
            $table->text('spesifikasi')->nullable();
            $table->string('pemilik')->nullable();
            $table->string('klasifikasi', 20)->nullable();
            $table->timestamps();
            $table->unique(['daftar_aset_virtual_id', 'urutan'], 'fr035_instance_order_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('smki_daftar_aset_virtual_instances');
        Schema::dropIfExists('smki_daftar_aset_virtuals');
    }
};
