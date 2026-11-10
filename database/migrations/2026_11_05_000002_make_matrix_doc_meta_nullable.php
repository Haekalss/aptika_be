<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Nomor dokumen / revisi / tanggal berlaku FR-031 tidak dipakai lagi.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE smki_matrix_competencies MODIFY COLUMN nomor_dokumen VARCHAR(255) NULL');
        DB::statement('ALTER TABLE smki_matrix_competencies MODIFY COLUMN no_revisi VARCHAR(50) NULL');
        DB::statement('ALTER TABLE smki_matrix_competencies MODIFY COLUMN tanggal_berlaku DATE NULL');
    }

    public function down(): void
    {
        DB::statement("UPDATE smki_matrix_competencies SET nomor_dokumen = '-' WHERE nomor_dokumen IS NULL");
        DB::statement("UPDATE smki_matrix_competencies SET no_revisi = '-' WHERE no_revisi IS NULL");
        DB::statement("UPDATE smki_matrix_competencies SET tanggal_berlaku = CURDATE() WHERE tanggal_berlaku IS NULL");
        DB::statement('ALTER TABLE smki_matrix_competencies MODIFY COLUMN nomor_dokumen VARCHAR(255) NOT NULL');
        DB::statement('ALTER TABLE smki_matrix_competencies MODIFY COLUMN no_revisi VARCHAR(50) NOT NULL');
        DB::statement('ALTER TABLE smki_matrix_competencies MODIFY COLUMN tanggal_berlaku DATE NOT NULL');
    }
};
