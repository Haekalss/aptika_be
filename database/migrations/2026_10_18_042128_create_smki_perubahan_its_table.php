<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Formulir Permintaan Perubahan TI / RFC (FR-015).
     *
     * Hampir semua kolom isian bersifat nullable karena status "Draft"
     * boleh disimpan tanpa validasi lengkap. Kelengkapan isian divalidasi
     * oleh controller saat formulir diajukan.
     */
    public function up(): void
    {
        Schema::create('smki_perubahan_its', function (Blueprint $table) {
            $table->id();

            // Identitas dokumen (terisi otomatis)
            $table->string('no_rfc', 30)->unique();
            $table->date('tanggal');
            $table->string('status', 20)->default('draft')->index();

            // Informasi Pemohon
            $table->string('pemohon', 255)->nullable();
            $table->string('unit_kerja', 255)->nullable();
            $table->string('perangkat_daerah', 255)->nullable();
            $table->string('nomor_surat_kontak', 255)->nullable();

            // Informasi Data
            $table->string('jenis_perubahan', 30)->nullable();
            $table->text('perubahan_diharapkan')->nullable();
            $table->text('latar_belakang')->nullable();
            $table->text('rincian_perubahan')->nullable();
            $table->string('tingkat_risiko', 20)->nullable();
            $table->text('keterangan_risiko')->nullable();
            $table->text('solusi_diharapkan')->nullable();
            $table->text('risiko_perubahan')->nullable();
            $table->text('alternatif_perubahan')->nullable();
            $table->text('biaya_perubahan')->nullable();
            $table->text('waktu_perubahan')->nullable();

            // Blok "Dibuat Oleh" (Pemohon)
            $table->dateTime('tanggal_diajukan')->nullable();
            $table->text('catatan_pengembalian')->nullable();

            // Blok "Diterima Oleh" (Koordinator Agen)
            $table->foreignId('diterima_oleh_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('diterima_oleh_nama', 255)->nullable();
            $table->dateTime('diterima_pada')->nullable();

            // Blok "Disetujui Oleh" (Penanggung Jawab Perangkat)
            $table->foreignId('disetujui_oleh_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('disetujui_oleh_nama', 255)->nullable();
            $table->dateTime('disetujui_pada')->nullable();
            $table->text('alasan_penolakan')->nullable();

            // Blok "Dilaksanakan Oleh" (Agen)
            $table->foreignId('dilaksanakan_oleh_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('dilaksanakan_oleh_nama', 255)->nullable();
            $table->dateTime('dilaksanakan_pada')->nullable();
            $table->date('tanggal_pelaksanaan')->nullable();
            $table->text('catatan_pelaksanaan')->nullable();

            // Audit: pengguna yang menginput data
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('smki_perubahan_its');
    }
};
