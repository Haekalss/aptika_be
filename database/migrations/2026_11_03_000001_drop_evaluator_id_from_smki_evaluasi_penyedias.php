<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * FR-021: Evaluator adalah perwakilan vendor (tanpa akun), sehingga
     * kolom evaluator_id (FK users) tidak dipakai lagi. Identitas evaluator
     * dicatat sebagai snapshot dari master penyedia saat pengajuan.
     */
    public function up(): void
    {
        Schema::table('smki_evaluasi_penyedias', function (Blueprint $table) {
            $table->dropForeign(['evaluator_id']);
            $table->dropIndex('fr021_evaluator_status_index');
            $table->dropColumn('evaluator_id');
        });
    }

    public function down(): void
    {
        Schema::table('smki_evaluasi_penyedias', function (Blueprint $table) {
            $table->foreignId('evaluator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->index(['evaluator_id', 'status'], 'fr021_evaluator_status_index');
        });
    }
};
