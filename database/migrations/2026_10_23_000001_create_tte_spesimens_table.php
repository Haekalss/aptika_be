<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Master Spesimen TTE.
 *
 * Menyimpan data penanda tangan yang dipakai untuk mencetak blok tanda tangan
 * elektronik pada dokumen (formulir SMKI, NDA, dsb).
 *
 * Catatan: migrasi ini hanya membuat struktur tabel — tidak menulis data apa pun,
 * sehingga aman dijalankan di Railway tanpa membawa data lokal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tte_spesimens', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('pegawai_id')->nullable();
            $table->string('nama');
            $table->string('nip', 30)->nullable();
            $table->string('jabatan')->nullable();
            $table->string('pangkat')->nullable();
            $table->string('golongan', 20)->nullable();
            $table->string('unit', 255)->default('DINAS KOMUNIKASI DAN INFORMATIKA');
            $table->string('kalimat', 255)->default('Ditandatangani secara elektronik oleh:');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('pegawai_id');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tte_spesimens');
    }
};
