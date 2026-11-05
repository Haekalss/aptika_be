<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('smki_review_hak_akses', function (Blueprint $table) {
            $table->id();
            $table->string('nomor_review')->nullable()->unique();
            $table->foreignId('bidang_id')->constrained('bidangs')->restrictOnDelete();
            $table->foreignId('recorder_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('recorder_name');
            $table->string('bidang_name');
            $table->unsignedBigInteger('object_id')->nullable();
            $table->string('object_name')->default('');
            $table->unsignedBigInteger('reviewer_id')->nullable();
            $table->string('reviewer_name')->default('');
            $table->string('status', 20)->default('Draf');
            $table->json('payload');
            $table->timestamps();
            $table->index(['created_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('smki_review_hak_akses');
    }
};
