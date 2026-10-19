<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Formulir Reviu Monitoring dan Pemeliharaan CCTV (FR-038/KOM.03.05/SANDIKAMI).
     */
    public function up(): void
    {
        // 1. Header formulir reviu CCTV
        if (!Schema::hasTable('smki_reviu_cctvs')) {
            Schema::create('smki_reviu_cctvs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->unsignedBigInteger('bidang_id')->nullable()->index();

                $table->date('tanggal_reviu');
                $table->date('periode_mulai')->nullable();
                $table->date('periode_selesai')->nullable();
                $table->string('obyek_reviu');
                $table->string('pelaksana_reviu');

                $table->string('pengelola_nama')->nullable();
                $table->string('pengelola_nip', 50)->nullable();

                $table->softDeletes();
                $table->timestamps();

                $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
                $table->foreign('bidang_id')->references('id')->on('bidangs')->nullOnDelete();
            });
        }

        // 2. Rincian reviu (baris tabel No / Uraian Reviu / Tindak Lanjut / Target Selesai / Keterangan)
        if (!Schema::hasTable('smki_reviu_cctv_items')) {
            Schema::create('smki_reviu_cctv_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('reviu_cctv_id')->index();
                $table->text('uraian_reviu');
                $table->text('tindak_lanjut')->nullable();
                $table->date('target_selesai')->nullable();
                $table->string('keterangan')->nullable();
                $table->timestamps();

                $table->foreign('reviu_cctv_id')
                    ->references('id')->on('smki_reviu_cctvs')
                    ->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('smki_reviu_cctv_items');
        Schema::dropIfExists('smki_reviu_cctvs');
    }
};
