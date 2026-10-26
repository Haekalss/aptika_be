<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('smki_log_perubahan', function (Blueprint $table) {
            $table->id();
            $table->string('nomor_log')->nullable()->unique();
            $table->string('client_log_id')->nullable();
            $table->foreignId('bidang_id')->constrained('bidangs');
            $table->foreignId('recorder_id')->constrained('users');
            $table->string('recorder_name');
            $table->string('unit_name');
            $table->string('object_kind', 10);
            $table->unsignedBigInteger('object_id');
            $table->string('object_label');
            $table->foreignId('executor_id')->nullable()->constrained('pegawai');
            $table->string('executor_name')->nullable();
            $table->string('status', 10)->default('Draf');
            $table->json('payload');
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();
            $table->index(['bidang_id', 'created_at']);
            $table->index(['bidang_id', 'status']);
            $table->unique(['bidang_id', 'client_log_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('smki_log_perubahan');
    }
};
