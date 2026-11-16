<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('smki_pemantauan_objeks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pemantauan_id')->constrained('smki_pemantauans')->cascadeOnDelete();
            $table->foreignId('objek_id')->constrained('smki_objeks')->restrictOnDelete();
            // Salinan nama objek — stabil walau master berubah / dinonaktifkan
            $table->string('objek_nama', 150);
            $table->timestamps();

            $table->unique(['pemantauan_id', 'objek_id'], 'smki_pemantauan_objek_unique');
            $table->index('objek_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('smki_pemantauan_objeks');
    }
};
