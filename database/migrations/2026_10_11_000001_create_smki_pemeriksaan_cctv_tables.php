<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Formulir Checklist Monitoring dan Pemeliharaan CCTV (FR-037).
     */
    public function up(): void
    {
        // 1. Header / sesi pemeriksaan
        if (!Schema::hasTable('smki_pemeriksaan_cctvs')) {
            Schema::create('smki_pemeriksaan_cctvs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->unsignedBigInteger('bidang_id')->nullable()->index();

                $table->enum('lingkup', ['Data Center', 'Gedung']);
                $table->string('perangkat_perekaman');
                $table->date('tanggal_pemeriksaan');
                $table->enum('kesesuaian_waktu', ['Sesuai', 'Tidak Sesuai'])->default('Sesuai');
                $table->string('nama_pemeriksa')->nullable();

                $table->softDeletes();
                $table->timestamps();

                $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
                $table->foreign('bidang_id')->references('id')->on('bidangs')->nullOnDelete();
            });
        }

        // 2. Rincian CCTV yang diperiksa (baris tabel pada template FR-037)
        if (!Schema::hasTable('smki_pemeriksaan_cctv_items')) {
            Schema::create('smki_pemeriksaan_cctv_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('pemeriksaan_cctv_id')->index();

                $table->string('kode_cctv');
                $table->string('nama_cctv');
                $table->string('lokasi');
                $table->enum('fungsi', ['Berfungsi', 'Mati'])->default('Berfungsi');
                $table->enum('rekaman', ['Baik', 'Buruk'])->default('Baik');
                $table->enum('obstacle', ['Ada', 'Tidak Ada'])->default('Tidak Ada');
                $table->text('keterangan')->nullable();
                $table->text('tindak_lanjut')->nullable();
                $table->string('petugas')->nullable();

                $table->timestamps();

                $table->foreign('pemeriksaan_cctv_id')
                    ->references('id')->on('smki_pemeriksaan_cctvs')
                    ->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('smki_pemeriksaan_cctv_items');
        Schema::dropIfExists('smki_pemeriksaan_cctvs');
    }
};
