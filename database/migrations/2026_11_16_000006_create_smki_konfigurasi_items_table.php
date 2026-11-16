<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('smki_konfigurasi_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pemantauan_objek_id')->constrained('smki_pemantauan_objeks')->cascadeOnDelete();
            // Nilai standar acuan (boleh null = baris manual tanpa standar).
            $table->foreignId('nilai_standar_id')->nullable()->constrained('smki_nilai_standars')->nullOnDelete();
            $table->string('nama_konfigurasi', 150);
            // Salinan nilai standar saat baris dibuat — tidak berubah walau standar master berubah belakangan
            $table->string('nilai_standar_salinan', 255)->nullable();
            $table->string('nilai_aktual', 255)->nullable();
            $table->string('kesesuaian', 20)->nullable(); // sesuai | tidak_sesuai
            $table->boolean('is_standar')->default(false);
            $table->text('keterangan')->nullable();
            $table->timestamps();

            $table->index('pemantauan_objek_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('smki_konfigurasi_items');
    }
};
