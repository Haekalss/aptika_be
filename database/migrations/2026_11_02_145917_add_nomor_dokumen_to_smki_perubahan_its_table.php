<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
{
    Schema::table('smki_perubahan_its', function (Blueprint $table) {
        $table->string('nomor_dokumen', 100)->nullable()->after('no_rfc');
    });

    // Isi data lama dengan nomor dokumen default
    DB::table('smki_perubahan_its')->whereNull('nomor_dokumen')
        ->update(['nomor_dokumen' => 'FR-015/KOM.03.05/SANDIKAMI']);
}

public function down(): void
{
    Schema::table('smki_perubahan_its', function (Blueprint $table) {
        $table->dropColumn('nomor_dokumen');
    });
}
};
