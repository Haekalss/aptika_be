<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('smki_objeks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('jenis_konfigurasi_id')->constrained('smki_jenis_konfigurasis')->restrictOnDelete();
            $table->string('nama', 100)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('jenis_konfigurasi_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('smki_objeks');
    }
};
