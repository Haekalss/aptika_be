<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('smki_perubahan_it_lampirans', function (Blueprint $table) {
            $table->id();

            $table->foreignId('perubahan_it_id')
                ->constrained('smki_perubahan_its')
                ->cascadeOnDelete();

            $table->string('nama_asli', 255);
            $table->string('path', 500);
            $table->string('mime', 150)->nullable();
            $table->unsignedBigInteger('ukuran')->default(0); // byte

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('smki_perubahan_it_lampirans');
    }
};
