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
        Schema::create('smki_aset_sdms', function (Blueprint $table) {
            $table->id();

            // Identitas pegawai
            $table->string('nama', 255);
            $table->string('nip', 30)->unique();

            // Informasi kepegawaian
            $table->string('pangkat_golongan', 255);
            $table->string('jabatan', 255);
            $table->date('tmt_jabatan')->nullable();
            $table->unsignedBigInteger('unit_kerja_id');
            $table->foreign('unit_kerja_id')
                ->references('id_unit_kerja')
                ->on('master_unit_kerja')
                ->restrictOnDelete();

            // Peran dan tanggung jawab SMKI
            $table->string('jabatan_smki', 255);
            $table->text('tanggung_jawab');

            // Pendidikan terakhir
            $table->string('jenjang', 20);
            $table->string('jurusan', 255);
            $table->unsignedSmallInteger('tahun_lulus');

            // Audit: pengguna yang menginput data
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // Waktu pembuatan dan pembaruan
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('smki_aset_sdms');
    }
};
