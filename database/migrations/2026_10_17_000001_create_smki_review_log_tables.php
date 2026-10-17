<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Formulir Review Log (FR-040/KOM.03.05/SANDIKAMI).
     */
    public function up(): void
    {
        // 1. Header formulir review log
        if (!Schema::hasTable('smki_review_logs')) {
            Schema::create('smki_review_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->unsignedBigInteger('bidang_id')->nullable()->index();

                $table->date('tanggal_review');
                $table->date('periode_mulai')->nullable();
                $table->date('periode_selesai')->nullable();
                $table->string('obyek_review');
                $table->string('pelaksana_review');
                $table->text('tindak_lanjut')->nullable();

                $table->string('dibuat_oleh_nama')->nullable();
                $table->string('dibuat_oleh_nip')->nullable();
                $table->string('mengetahui_nama')->nullable();
                $table->string('mengetahui_nip')->nullable();
                $table->string('ditandatangani_di')->nullable();
                $table->date('tanggal_ttd')->nullable();

                $table->softDeletes();
                $table->timestamps();

                $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
                $table->foreign('bidang_id')->references('id')->on('bidangs')->nullOnDelete();
            });
        }

        // 2. Rincian log (baris tabel No / User / IP / Tanggal / Waktu / Durasi)
        if (!Schema::hasTable('smki_review_log_items')) {
            Schema::create('smki_review_log_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('review_log_id')->index();
                $table->string('user_log');
                $table->string('ip_address', 45);
                $table->date('tanggal');
                $table->time('waktu');
                $table->string('durasi', 50);
                $table->timestamps();

                $table->foreign('review_log_id')
                    ->references('id')->on('smki_review_logs')
                    ->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('smki_review_log_items');
        Schema::dropIfExists('smki_review_logs');
    }
};
