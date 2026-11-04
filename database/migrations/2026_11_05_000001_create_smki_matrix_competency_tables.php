<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Matriks Kompetensi SMKI (FR-031): dokumen + matriks peran + matriks pegawai + rencana training.
     */
    public function up(): void
    {
        if (!Schema::hasTable('smki_matrix_competencies')) {
            Schema::create('smki_matrix_competencies', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->unsignedBigInteger('bidang_id')->nullable()->index();
                $table->string('nomor_dokumen', 255);
                $table->string('no_revisi', 50);
                $table->date('tanggal_berlaku');
                $table->string('nama_unit_kerja', 255)->nullable();
                $table->json('competencies')->nullable();
                $table->timestamps();

                $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
                $table->foreign('bidang_id')->references('id')->on('bidangs')->nullOnDelete();
            });
        }

        if (!Schema::hasTable('smki_matrix_roles')) {
            Schema::create('smki_matrix_roles', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('document_id')->index();
                $table->string('unit_label', 255);
                $table->string('peran', 50);
                $table->json('levels');
                $table->timestamps();

                $table->foreign('document_id')
                    ->references('id')->on('smki_matrix_competencies')
                    ->cascadeOnDelete();
            });
        }

        if (!Schema::hasTable('smki_matrix_employees')) {
            Schema::create('smki_matrix_employees', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('document_id')->index();
                $table->unsignedBigInteger('aset_sdm_id')->nullable()->index();
                $table->string('nama', 255);
                $table->string('nip', 100)->nullable();
                $table->string('jabatan', 255);
                $table->string('peran', 50);
                $table->json('levels');
                $table->text('usulan_training')->nullable();
                $table->timestamps();

                $table->foreign('document_id')
                    ->references('id')->on('smki_matrix_competencies')
                    ->cascadeOnDelete();
            });
        }

        if (!Schema::hasTable('smki_matrix_trainings')) {
            Schema::create('smki_matrix_trainings', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('document_id')->index();
                $table->string('nama', 255);
                $table->string('jabatan', 255);
                $table->text('usulan_training');
                $table->timestamps();

                $table->foreign('document_id')
                    ->references('id')->on('smki_matrix_competencies')
                    ->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('smki_matrix_trainings');
        Schema::dropIfExists('smki_matrix_employees');
        Schema::dropIfExists('smki_matrix_roles');
        Schema::dropIfExists('smki_matrix_competencies');
    }
};
