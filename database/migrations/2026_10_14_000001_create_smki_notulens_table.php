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
        // 1. Tabel Utama Notulen SMKI
        Schema::create('smki_notulens', function (Blueprint $table) {
            $table->id();
            $table->string('nomor_notulen')->unique(); // contoh: NM-2026-001
            
            // Identitas Dokumen
            $table->string('no_dokumen')->default('F10-SMKI');
            $table->string('no_revisi')->default('1.1');
            $table->date('tanggal_berlaku')->default('2022-01-20');
            $table->string('judul_formulir')->default('Formulir Notulen Management Review SMKI');
            
            // Pelaksanaan Rapat
            $table->string('judul_rapat')->default('ISO 27001:2013 Management Review');
            $table->date('tanggal');
            $table->string('waktu_mulai', 10)->default('09:00');
            $table->string('waktu_selesai', 10)->default('12:00');
            $table->string('zona_waktu', 10)->default('WIB');
            $table->string('tempat');
            $table->string('notulis');
            $table->string('koordinator_smki');
            
            // Foreign key user / bidang jika ada
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('bidang_id')->nullable();
            
            // Tujuan & Tindak Lanjut
            $table->text('tujuan')->nullable();
            $table->text('tindak_lanjut')->nullable();
            
            $table->timestamps();
            $table->softDeletes();
        });

        // 2. Tabel Peserta Rapat
        Schema::create('smki_notulen_pesertas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('smki_notulen_id')->constrained('smki_notulens')->onDelete('cascade');
            $table->unsignedBigInteger('pegawai_id')->nullable();
            $table->string('nama');
            $table->string('jabatan');
            $table->integer('urutan')->default(1);
            $table->timestamps();
        });

        // 3. Tabel Pembahasan 10 Agenda (ISO 27001 Clause 9.3)
        Schema::create('smki_notulen_agendas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('smki_notulen_id')->constrained('smki_notulens')->onDelete('cascade');
            $table->integer('nomor_agenda'); // 1 s.d 10
            $table->string('agenda_item');
            $table->string('clause_iso')->nullable(); // contoh: 9.3 a
            $table->text('outcomes')->nullable();
            $table->text('actions_output')->nullable();
            $table->string('pic')->nullable();
            $table->date('tgl_penyelesaian')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('smki_notulen_agendas');
        Schema::dropIfExists('smki_notulen_pesertas');
        Schema::dropIfExists('smki_notulens');
    }
};
