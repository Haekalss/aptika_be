<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Tabel Header / Parent: Dokumen Formulir Aset Informasi (FR-028)
        if (!Schema::hasTable('smki_formulir_aset_informasis')) {
            Schema::create('smki_formulir_aset_informasis', function (Blueprint $table) {
                $table->id();
                $table->string('no_formulir', 100)->default('FR-028-SMKI')->index();
                $table->string('revisi', 20)->default('0');
                $table->date('tanggal');
                $table->string('bidang_nama', 100)->default('Bidang APTIKA');
                $table->string('klasifikasi_dokumen', 100)->default('Dokumen Terbatas');
                $table->unsignedBigInteger('bidang_id')->nullable()->default(3)->index(); // Default: APTIKA (3)
                $table->unsignedBigInteger('user_id')->nullable()->index(); // Pembuat
                $table->text('catatan')->nullable();
                $table->softDeletes();
                $table->timestamps();

                if (Schema::hasTable('users')) {
                    $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
                }
                if (Schema::hasTable('bidangs')) {
                    $table->foreign('bidang_id')->references('id')->on('bidangs')->nullOnDelete();
                }
            });
        }

        // 2. Tabel Detail Tab 1: Aset Informasi Unit Kerja (Dokumen, Data, Source Code, dll - 12 Kolom)
        if (!Schema::hasTable('smki_aset_informasi_unit_kerjas')) {
            Schema::create('smki_aset_informasi_unit_kerjas', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('formulir_id');
                $table->integer('no_urut')->default(1);
                $table->string('kode_aset', 100)->nullable(); // Kolom 2: Kode Aset Informasi
                $table->string('nama_aset', 255);             // Kolom 3: Nama Aset Informasi
                $table->string('klasifikasi', 50)->default('Internal'); // Kolom 4: Publik, Internal, Rahasia
                $table->string('tahun_pembuatan', 10)->nullable();      // Kolom 5: Tahun Pembuatan pertama
                $table->string('tahun_update', 10)->nullable();         // Kolom 6: Tahun terakhir update
                $table->text('deskripsi')->nullable();                  // Kolom 7: Deskripsi singkat
                $table->string('format_penyimpanan', 50)->default('Softcopy'); // Kolom 8: Hardcopy / Softcopy
                $table->string('masa_simpan', 100)->nullable();         // Kolom 9: Retensi / masa simpan
                $table->string('lokasi_penyimpanan', 255)->nullable();  // Kolom 10: Tempat / lokasi simpan
                $table->string('pemilik_aset', 255)->nullable();        // Kolom 11: Unit kerja penanggung jawab
                $table->text('keterangan')->nullable();                 // Kolom 12: Keterangan (dimusnahkan, aktif, dll)
                $table->timestamps();

                $table->foreign('formulir_id')
                    ->references('id')
                    ->on('smki_formulir_aset_informasis')
                    ->onDelete('cascade');
            });
        }

        // 3. Tabel Detail Tab 2: Aset Intangible (Aset Tak Berwujud & CIA - 10 Kolom)
        if (!Schema::hasTable('smki_aset_informasi_intangibles')) {
            Schema::create('smki_aset_informasi_intangibles', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('formulir_id');
                $table->integer('no_urut')->default(1);
                $table->string('kode_aset', 100)->nullable(); // Kolom 1 (No): misal INT-001
                $table->string('layanan', 255)->nullable();   // Kolom 2: Layanan (Fintech, SPBE, dll)
                $table->string('nama_aset', 255);             // Kolom 3: Nama Aset (Reputasi, Pengetahuan, dll)
                $table->string('sub_klasifikasi', 255)->nullable(); // Kolom 4: Sub Klasifikasi
                $table->string('pemilik_aset', 255)->nullable();    // Kolom 5: Pemilik Aset
                $table->tinyInteger('kerahasiaan')->default(1);      // Kolom 6: C (1-3)
                $table->tinyInteger('integritas')->default(1);       // Kolom 7: I (1-3)
                $table->tinyInteger('ketersediaan')->default(1);     // Kolom 8: A (1-3)
                $table->tinyInteger('nilai')->default(1);            // Kolom 9: Nilai = max(C,I,A)
                $table->string('keterangan', 50)->default('Rendah'); // Kolom 10: Tinggi / Sedang / Rendah
                $table->timestamps();

                $table->foreign('formulir_id')
                    ->references('id')
                    ->on('smki_formulir_aset_informasis')
                    ->onDelete('cascade');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('smki_aset_informasi_intangibles');
        Schema::dropIfExists('smki_aset_informasi_unit_kerjas');
        Schema::dropIfExists('smki_formulir_aset_informasis');
    }
};
