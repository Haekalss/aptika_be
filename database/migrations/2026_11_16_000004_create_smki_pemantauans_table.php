<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('smki_pemantauans', function (Blueprint $table) {
            $table->id();

            // Identitas Dokumen (kop formulir) — input manual
            $table->string('no_formulir', 120)->nullable();
            $table->string('no_revisi', 30)->default('0.0');
            $table->date('tanggal_berlaku')->nullable();

            // Identifikasi Pemantauan
            $table->date('tanggal_pemantauan');
            $table->unsignedTinyInteger('periode'); // 1..4 (triwulan)
            $table->foreignId('jenis_konfigurasi_id')->constrained('smki_jenis_konfigurasis')->restrictOnDelete();

            // Status & hasil
            $table->string('status', 20)->default('draft'); // draft | diajukan | revisi | selesai
            $table->string('hasil', 30)->nullable(); // sesuai_seluruhnya | ada_ketidaksesuaian
            $table->unsignedSmallInteger('jumlah_sesuai')->default(0);
            $table->unsignedSmallInteger('jumlah_tidak_sesuai')->default(0);

            $table->text('catatan_tindak_lanjut')->nullable();
            $table->text('catatan_admin')->nullable();

            // Penanda tangan
            $table->foreignId('pelaksana_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('admin_id')->nullable()->constrained('users')->nullOnDelete();

            // Snapshot TTE — dikunci saat TTD agar histori tidak berubah
            $table->string('ttd_pelaksana_nama', 150)->nullable();
            $table->string('ttd_pelaksana_jabatan', 255)->nullable();
            $table->string('ttd_admin_nama', 150)->nullable();
            $table->string('ttd_admin_jabatan', 255)->nullable();
            $table->timestamp('tanggal_ttd_pelaksana')->nullable();
            $table->timestamp('tanggal_ttd_admin')->nullable();

            $table->unsignedTinyInteger('siklus')->default(1);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('pelaksana_id');
            $table->index('admin_id');
            $table->index('jenis_konfigurasi_id');
            $table->index('tanggal_pemantauan');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('smki_pemantauans');
    }
};
