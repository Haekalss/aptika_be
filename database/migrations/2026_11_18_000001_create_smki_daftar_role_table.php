<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('smki_daftar_role', function (Blueprint $table) {
            $table->id();
            $table->string('nomor')->nullable()->unique();
            $table->string('client_id')->nullable();
            $table->foreignId('bidang_id')->constrained('bidangs');
            $table->foreignId('recorder_id')->constrained('users');
            $table->string('recorder_name');
            $table->string('unit_name');
            $table->string('status', 10)->default('Draf');
            $table->json('payload');
            $table->timestamps();

            $table->index(['bidang_id', 'created_at']);
            $table->index(['bidang_id', 'status']);
            $table->unique(['bidang_id', 'client_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('smki_daftar_role');
    }
};
