<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Riwayat tahapan perubahan TI (pengajuan, pemeriksaan, persetujuan, pelaksanaan).
     */
    public function up(): void
    {
        Schema::create('smki_perubahan_it_riwayats', function (Blueprint $table) {
            $table->id();

            $table->foreignId('perubahan_it_id')
                ->constrained('smki_perubahan_its')
                ->cascadeOnDelete();

            $table->string('aksi', 30);
            $table->string('status_dari', 20)->nullable();
            $table->string('status_ke', 20);
            $table->text('catatan')->nullable();

            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('nama_user', 255);

            $table->timestamps();

            $table->index(['perubahan_it_id', 'id'], 'smki_perubahan_it_riwayat_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('smki_perubahan_it_riwayats');
    }
};
