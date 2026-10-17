<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Formulir Bring Your Own Device / Penggunaan Perangkat Pribadi (FR-045/KOM.03/05/SANDIKAMI).
     */
    public function up(): void
    {
        // 1. Header surat pernyataan (pejabat penandatangan / Kepala Unit Kerja)
        if (!Schema::hasTable('smki_byod_forms')) {
            Schema::create('smki_byod_forms', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->unsignedBigInteger('bidang_id')->nullable()->index();

                $table->string('nama');
                $table->string('nip', 50)->nullable();
                $table->string('jabatan');
                $table->string('unit_kerja');
                $table->string('ditandatangani_di')->nullable();
                $table->date('tanggal_ttd')->nullable();

                $table->softDeletes();
                $table->timestamps();

                $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
                $table->foreign('bidang_id')->references('id')->on('bidangs')->nullOnDelete();
            });
        }

        // 2. Rincian perangkat pribadi (baris tabel pada template FR-045)
        if (!Schema::hasTable('smki_byod_devices')) {
            Schema::create('smki_byod_devices', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('byod_form_id')->index();
                $table->string('nama');
                $table->string('nip', 50)->nullable();
                $table->enum('jenis_perangkat', ['Laptop', 'PC', 'Tab']);
                $table->string('merek_tipe');
                $table->string('nomor_seri');
                $table->timestamps();

                $table->foreign('byod_form_id')
                    ->references('id')->on('smki_byod_forms')
                    ->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('smki_byod_devices');
        Schema::dropIfExists('smki_byod_forms');
    }
};
