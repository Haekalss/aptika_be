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
        Schema::create('smki_aset_sdm_pelatihans', function (Blueprint $table) {
            $table->id();

            // Relasi ke data SDM
            $table->foreignId('aset_sdm_id')
                ->constrained('smki_aset_sdms')
                ->cascadeOnDelete();

            // Informasi pelatihan
            $table->string('nama_pelatihan', 255);
            $table->unsignedSmallInteger('tahun');

            $table->timestamps();

            // Opsional: indeks untuk pencarian riwayat per SDM
            $table->index(
                ['aset_sdm_id', 'tahun'],
                'smki_aset_sdm_pelatihan_tahun_idx'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('smki_aset_sdm_pelatihans');
    }
};
