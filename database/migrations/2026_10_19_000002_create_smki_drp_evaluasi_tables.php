<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Formulir Laporan Evaluasi dan Pengujian DRP (FR-039/KOM.03.05/SANDIKAMI).
     */
    public function up(): void
    {
        // 1. Header laporan (bagian A) + checklist BCP (bagian B) + tanda tangan
        if (!Schema::hasTable('smki_drp_evaluasis')) {
            Schema::create('smki_drp_evaluasis', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->unsignedBigInteger('bidang_id')->nullable()->index();

                $table->date('tanggal_pengujian');
                $table->time('waktu_mulai')->nullable();
                $table->time('waktu_selesai')->nullable();
                $table->text('tujuan');
                $table->text('cakupan');
                $table->text('tipe_pengujian');
                $table->text('asumsi_bencana')->nullable();
                $table->text('asumsi_sumber_daya')->nullable();

                // Checklist BCP: { "kelengkapan_1": true, ... } (kunci tetap, lihat SmkiDrpEvaluasi::CHECKLIST)
                $table->json('checklist')->nullable();
                $table->text('lampiran')->nullable();

                $table->string('dibuat_oleh_nama')->nullable();
                $table->date('dibuat_oleh_tanggal')->nullable();
                $table->string('diperiksa_oleh_nama')->nullable();
                $table->date('diperiksa_oleh_tanggal')->nullable();
                $table->string('disetujui_oleh_nama')->nullable();
                $table->date('disetujui_oleh_tanggal')->nullable();

                $table->softDeletes();
                $table->timestamps();

                $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
                $table->foreign('bidang_id')->references('id')->on('bidangs')->nullOnDelete();
            });
        }

        // 2. Evaluasi - Persyaratan Waktu (Sistem TI / RTO Ideal / Pencapaian RTO / Kendala)
        if (!Schema::hasTable('smki_drp_evaluasi_waktus')) {
            Schema::create('smki_drp_evaluasi_waktus', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('drp_evaluasi_id')->index();
                $table->string('sistem_ti');
                $table->string('rto_ideal')->nullable();
                $table->string('pencapaian_rto')->nullable();
                $table->text('kendala')->nullable();
                $table->timestamps();

                $table->foreign('drp_evaluasi_id')
                    ->references('id')->on('smki_drp_evaluasis')
                    ->cascadeOnDelete();
            });
        }

        // 3. Evaluasi - Proses DRP (Proses / Aktivitas / Hasil Evaluasi / Rekomendasi / PIC)
        if (!Schema::hasTable('smki_drp_evaluasi_proses')) {
            Schema::create('smki_drp_evaluasi_proses', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('drp_evaluasi_id')->index();
                $table->string('proses');
                $table->text('aktivitas')->nullable();
                $table->text('hasil_evaluasi')->nullable();
                $table->text('rekomendasi')->nullable();
                $table->string('pic')->nullable();
                $table->timestamps();

                $table->foreign('drp_evaluasi_id')
                    ->references('id')->on('smki_drp_evaluasis')
                    ->cascadeOnDelete();
            });
        }

        // 4. Evaluasi - Team (Peran / Tanggung Jawab / Evaluasi / Rekomendasi)
        if (!Schema::hasTable('smki_drp_evaluasi_tims')) {
            Schema::create('smki_drp_evaluasi_tims', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('drp_evaluasi_id')->index();
                $table->string('peran');
                $table->text('tanggung_jawab')->nullable();
                $table->text('evaluasi')->nullable();
                $table->text('rekomendasi')->nullable();
                $table->timestamps();

                $table->foreign('drp_evaluasi_id')
                    ->references('id')->on('smki_drp_evaluasis')
                    ->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('smki_drp_evaluasi_tims');
        Schema::dropIfExists('smki_drp_evaluasi_proses');
        Schema::dropIfExists('smki_drp_evaluasi_waktus');
        Schema::dropIfExists('smki_drp_evaluasis');
    }
};
