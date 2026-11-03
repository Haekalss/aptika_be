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
        // 1. Tabel Master: Jabatan
        if (!Schema::hasTable('jabatan')) {
            Schema::create('jabatan', function (Blueprint $table) {
                $table->increments('id_jabatan');
                $table->string('nama_jabatan', 150);
                $table->timestamps();
            });
        }

        // 2. Tabel Master: Bidang (sesuai ERD)
        if (!Schema::hasTable('bidang')) {
            Schema::create('bidang', function (Blueprint $table) {
                $table->increments('id_bidang');
                $table->string('nama_bidang', 150);
                $table->timestamps();
            });
        }

        // 3. Tabel Master: Item Screening
        if (!Schema::hasTable('item_screening')) {
            Schema::create('item_screening', function (Blueprint $table) {
                $table->increments('id_item');
                $table->string('nama_item', 255);
                $table->timestamps();
            });
        }

        // 4. Tabel Utama: Screening Karyawan
        if (!Schema::hasTable('screening_karyawan')) {
            Schema::create('screening_karyawan', function (Blueprint $table) {
                $table->increments('id_screening');
                $table->string('nama_karyawan', 255);
                $table->unsignedInteger('id_jabatan');
                $table->unsignedInteger('id_bidang');
                $table->integer('tahun')->default(2026);
                $table->unsignedInteger('id_detail')->nullable(); // Sesuai ERD
                $table->date('tanggal_screening');
                $table->enum('kesimpulan', [
                    'Perlu melengkapi administrasi',
                    'Administrasi Lengkap'
                ])->default('Administrasi Lengkap');
                $table->text('catatan')->nullable();
                $table->softDeletes();
                $table->timestamps(); // create & update

                $table->foreign('id_jabatan')
                    ->references('id_jabatan')
                    ->on('jabatan')
                    ->cascadeOnDelete();

                $table->foreign('id_bidang')
                    ->references('id_bidang')
                    ->on('bidang')
                    ->cascadeOnDelete();
            });
        }

        // 5. Tabel Rincian: Detail Screening
        if (!Schema::hasTable('detail_screening')) {
            Schema::create('detail_screening', function (Blueprint $table) {
                $table->increments('id_detail');
                $table->unsignedInteger('id_screening');
                $table->unsignedInteger('id_item');
                $table->enum('status', ['Ada', 'Tidak'])->default('Ada');
                $table->text('keterangan')->nullable();
                $table->enum('kesimpulan', [
                    'Perlu melengkapi administrasi',
                    'Administrasi Lengkap'
                ])->nullable();
                $table->text('catatan')->nullable();
                $table->timestamps();

                $table->foreign('id_screening')
                    ->references('id_screening')
                    ->on('screening_karyawan')
                    ->cascadeOnDelete();

                $table->foreign('id_item')
                    ->references('id_item')
                    ->on('item_screening')
                    ->cascadeOnDelete();
            });
        }

        // SEED DATA MASTER
        // Seed Item Screening (5 Item Wajib)
        $items = [
            'Data Identitas (KTP, NPWP, KK)',
            'Tingkat Pendidikan (Ijazah & Sertifikat)',
            'Pemberi Referensi',
            'Pengalaman Kerja Sebelumnya',
            'Catatan lain yang diperlukan',
        ];
        foreach ($items as $idx => $item) {
            DB::table('item_screening')->updateOrInsert(
                ['id_item' => $idx + 1],
                ['nama_item' => $item, 'created_at' => now(), 'updated_at' => now()]
            );
        }

        // Seed Jabatan
        $jabatans = [
            'Staff IT',
            'Admin',
            'Programmer',
            'Staff Jaringan',
            'Teknisi',
            'Analis Sistem',
            'Security Officer',
            'Database Administrator',
        ];
        foreach ($jabatans as $idx => $j) {
            DB::table('jabatan')->updateOrInsert(
                ['id_jabatan' => $idx + 1],
                ['nama_jabatan' => $j, 'created_at' => now(), 'updated_at' => now()]
            );
        }

        // Seed Bidang
        $bidangs = [
            'Aptika',
            'Persandian',
            'Informasi dan Komunikasi Publik (IKP)',
            'E-Government',
            'Statistik',
            'Sekretariat',
            'UPTD PLDDIG',
        ];
        foreach ($bidangs as $idx => $b) {
            DB::table('bidang')->updateOrInsert(
                ['id_bidang' => $idx + 1],
                ['nama_bidang' => $b, 'created_at' => now(), 'updated_at' => now()]
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('detail_screening');
        Schema::dropIfExists('screening_karyawan');
        Schema::dropIfExists('item_screening');
        Schema::dropIfExists('bidang');
        Schema::dropIfExists('jabatan');
    }
};
