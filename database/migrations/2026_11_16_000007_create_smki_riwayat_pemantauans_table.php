<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only: tidak ada updated_at, tidak pernah di-update/delete.
        Schema::create('smki_riwayat_pemantauans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pemantauan_id')->constrained('smki_pemantauans')->cascadeOnDelete();
            $table->foreignId('pelaku_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('pelaku_nama', 150);
            $table->string('aksi', 30); // dibuat | diajukan | diajukan_ulang | dikembalikan | disetujui
            $table->unsignedTinyInteger('siklus')->default(1);
            $table->string('hasil', 30)->nullable();
            $table->text('catatan')->nullable();
            // Snapshot penuh daftar konfigurasi & ringkasan pada momen aksi (untuk audit & cetak historis)
            $table->json('snapshot')->nullable();
            $table->timestamp('created_at');

            $table->index(['pemantauan_id', 'id'], 'smki_riwayat_pemantauan_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('smki_riwayat_pemantauans');
    }
};
