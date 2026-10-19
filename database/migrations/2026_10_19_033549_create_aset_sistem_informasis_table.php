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
        Schema::create('aset_sistem_informasi', function (Blueprint $table) {
            $table->id();
            $table->string('kode_aset_ti')->nullable();
            $table->string('kode_virtualisasi')->nullable();
            $table->string('kode_sistem_info')->nullable();
            $table->string('nama_sistem');
            $table->string('ip_address')->nullable();
            $table->text('deskripsi')->nullable();
            $table->text('spesifikasi'); // Framework, DB, dll
            $table->string('kategori_sistem')->nullable(); // Aplikasi Web / Server
            $table->string('pemilik')->nullable();
            $table->string('klasifikasi')->nullable(); // Rahasia / Internal / Publik
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('aset_sistem_informasi');
    }
};