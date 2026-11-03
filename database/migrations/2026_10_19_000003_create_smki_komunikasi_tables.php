<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('smki_komunikasi_headers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('bidang_id')->nullable()->index();
            $table->string('periode', 4);
            $table->string('no_dokumen', 100);
            $table->string('no_revisi', 30);
            $table->string('tanggal_berlaku', 100);
            $table->timestamps();

            $table->foreign('bidang_id')->references('id')->on('bidangs')->nullOnDelete();
            $table->unique(['bidang_id', 'periode']);
        });

        Schema::create('smki_komunikasis', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('bidang_id')->nullable()->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->enum('tipe', ['internal', 'eksternal']);
            $table->string('judul');
            $table->string('klasifikasi', 50);
            $table->string('pengirim');
            $table->string('penerima');
            $table->string('frekuensi', 100);
            $table->string('metode', 255);
            $table->string('periode', 4);
            $table->softDeletes();
            $table->timestamps();

            $table->foreign('bidang_id')->references('id')->on('bidangs')->nullOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['periode', 'tipe']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('smki_komunikasis');
        Schema::dropIfExists('smki_komunikasi_headers');
    }
};
