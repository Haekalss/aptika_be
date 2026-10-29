<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('laporan_kerusakan_data_centers')) {
            return;
        }
        Schema::table('laporan_kerusakan_data_centers', function (Blueprint $table) {
            if (!Schema::hasColumn('laporan_kerusakan_data_centers', 'no_dokumen')) {
                $table->string('no_dokumen', 100)->default('F24-SMKI')->after('nomor_laporan');
            }
            if (!Schema::hasColumn('laporan_kerusakan_data_centers', 'no_revisi')) {
                $table->string('no_revisi', 50)->default('1.0')->after('no_dokumen');
            }
            if (!Schema::hasColumn('laporan_kerusakan_data_centers', 'tanggal_berlaku')) {
                $table->date('tanggal_berlaku')->nullable()->after('no_revisi');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('laporan_kerusakan_data_centers')) {
            return;
        }
        Schema::table('laporan_kerusakan_data_centers', function (Blueprint $table) {
            foreach (['no_dokumen', 'no_revisi', 'tanggal_berlaku'] as $col) {
                if (Schema::hasColumn('laporan_kerusakan_data_centers', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};

