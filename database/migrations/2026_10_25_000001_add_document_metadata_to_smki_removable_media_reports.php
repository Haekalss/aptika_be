<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('smki_removable_media_reports', function (Blueprint $table) {
            $table->string('nomor_dokumen')->default('FR-025/KOM.03.05/ SANDIKAMI');
            $table->string('nomor_revisi', 50)->default('1.0');
            $table->date('tanggal_berlaku')->default('2022-07-07');
        });
    }

    public function down(): void
    {
        Schema::table('smki_removable_media_reports', function (Blueprint $table) {
            $table->dropColumn(['nomor_dokumen', 'nomor_revisi', 'tanggal_berlaku']);
        });
    }
};
