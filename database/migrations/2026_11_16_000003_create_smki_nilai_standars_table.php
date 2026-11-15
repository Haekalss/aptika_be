<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('smki_nilai_standars', function (Blueprint $table) {
            $table->id();
            $table->foreignId('objek_id')->constrained('smki_objeks')->restrictOnDelete();
            $table->string('nama_konfigurasi', 150);
            $table->string('nilai_standar', 255);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['objek_id', 'is_active'], 'smki_nilai_standar_objek_aktif_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('smki_nilai_standars');
    }
};
