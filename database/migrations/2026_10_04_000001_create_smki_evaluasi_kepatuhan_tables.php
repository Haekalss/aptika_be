<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Formulir Evaluasi Kepatuhan Terhadap Regulasi dan Aturan Lain (FR-044).
     */
    public function up(): void
    {
        // 1. Header / sesi evaluasi
        if (!Schema::hasTable('smki_evaluasi_kepatuhans')) {
            Schema::create('smki_evaluasi_kepatuhans', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->unsignedBigInteger('bidang_id')->nullable()->index();

                $table->date('tanggal_evaluasi');
                $table->string('ditandatangani_di')->nullable();
                $table->date('tanggal_ttd')->nullable();

                $table->softDeletes();
                $table->timestamps();

                $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
                $table->foreign('bidang_id')->references('id')->on('bidangs')->nullOnDelete();
            });
        }

        // 2. Rincian dokumen kepatuhan (baris tabel pada template FR-044)
        if (!Schema::hasTable('smki_evaluasi_kepatuhan_items')) {
            Schema::create('smki_evaluasi_kepatuhan_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('evaluasi_kepatuhan_id')->index();
                $table->string('dokumen_kepatuhan');
                $table->text('deskripsi');
                $table->enum('evaluasi_status', ['Patuh', 'Tidak Patuh', 'Dalam Proses'])->default('Dalam Proses');
                $table->timestamps();

                $table->foreign('evaluasi_kepatuhan_id')
                    ->references('id')->on('smki_evaluasi_kepatuhans')
                    ->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('smki_evaluasi_kepatuhan_items');
        Schema::dropIfExists('smki_evaluasi_kepatuhans');
    }
};
