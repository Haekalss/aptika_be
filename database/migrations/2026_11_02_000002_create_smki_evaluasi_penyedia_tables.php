<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('smki_evaluasi_kriterias', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('urutan');
            $table->string('nama_kriteria', 255);
            $table->timestamps();
        });

        Schema::create('smki_skala_nilais', function (Blueprint $table) {
            $table->id();
            $table->string('label', 50)->unique();
            $table->unsignedSmallInteger('skor');
            $table->timestamps();
        });

        Schema::create('smki_rekomendasi_evaluasis', function (Blueprint $table) {
            $table->id();
            $table->string('kategori', 100);
            $table->unsignedSmallInteger('nilai_min');
            $table->unsignedSmallInteger('nilai_max');
            $table->string('keterangan', 255);
            $table->timestamps();
            $table->unique(['nilai_min', 'nilai_max'], 'fr021_rekomendasi_rentang_unique');
        });

        Schema::create('smki_penyedia_jasas', function (Blueprint $table) {
            $table->id();
            $table->string('nama_penyedia', 255)->index();
            $table->string('contact_person', 255)->nullable();
            $table->string('email', 255)->nullable();
            $table->timestamps();
        });

        Schema::create('smki_evaluasi_penyedias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('penyedia_id')->constrained('smki_penyedia_jasas')->restrictOnDelete();
            $table->foreignId('evaluator_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('rekomendasi_id')->nullable()
                ->constrained('smki_rekomendasi_evaluasis')->nullOnDelete();

            $table->string('no_kontrak', 100);
            $table->string('nomor_dokumen', 100)->nullable();
            $table->string('nomor_revisi', 50)->nullable();
            // Nilai ini mengikuti teks pada template (mis. "07 Juli 2022"), bukan tanggal ISO.
            $table->string('tanggal_berlaku', 100)->nullable();
            $table->string('sifat_pekerjaan', 30)->default('pengadaan_baru');
            $table->string('sifat_pekerjaan_lainnya', 255)->nullable();
            $table->unsignedSmallInteger('total_nilai')->nullable();
            $table->string('status', 20)->default('draft');
            $table->text('catatan_revisi')->nullable();
            $table->unsignedTinyInteger('siklus')->default(1);
            $table->string('tempat', 150)->nullable();
            $table->date('tanggal_evaluasi');

            // Snapshot TTE Evaluator (identitas yang dipilih pada saat pengajuan).
            $table->string('ttd_evaluator_nama', 255)->nullable();
            $table->string('ttd_evaluator_nip', 50)->nullable();
            $table->string('ttd_evaluator_jabatan', 255)->nullable();
            $table->timestamp('tanggal_ttd_evaluator')->nullable();

            // Snapshot TTE Atasan (identitas admin yang menyetujui).
            $table->string('ttd_admin_nama', 255)->nullable();
            $table->string('ttd_admin_nip', 50)->nullable();
            $table->string('ttd_admin_jabatan', 255)->nullable();
            $table->timestamp('tanggal_ttd_admin')->nullable();

            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['created_by', 'status'], 'fr021_created_status_index');
            $table->index(['evaluator_id', 'status'], 'fr021_evaluator_status_index');
            $table->index(['admin_id', 'status'], 'fr021_admin_status_index');
            $table->index('no_kontrak', 'fr021_no_kontrak_index');
        });

        Schema::create('smki_evaluasi_penyedia_penilaians', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evaluasi_id')
                ->constrained('smki_evaluasi_penyedias')->cascadeOnDelete();
            $table->foreignId('kriteria_id')
                ->constrained('smki_evaluasi_kriterias')->restrictOnDelete();
            $table->foreignId('skala_id')
                ->constrained('smki_skala_nilais')->restrictOnDelete();
            $table->unsignedSmallInteger('skor');
            $table->timestamps();

            $table->unique(['evaluasi_id', 'kriteria_id'], 'fr021_penilaian_unique');
        });

        Schema::create('smki_evaluasi_penyedia_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evaluasi_id')
                ->constrained('smki_evaluasi_penyedias')->cascadeOnDelete();
            $table->string('aksi', 20);
            $table->unsignedTinyInteger('siklus')->default(1);
            $table->text('catatan')->nullable();
            $table->unsignedSmallInteger('total_nilai')->nullable();
            $table->foreignId('rekomendasi_id')->nullable()
                ->constrained('smki_rekomendasi_evaluasis')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('penilaian_snapshot')->nullable();
            $table->json('tte_snapshot')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['evaluasi_id', 'created_at'], 'fr021_log_created_index');
        });

        $this->seedMasterData();
    }

    private function seedMasterData(): void
    {
        $now = now();

        $criteria = [
            ['urutan' => 1, 'nama_kriteria' => 'Ketepatan waktu pelaksanaan pekerjaan'],
            ['urutan' => 2, 'nama_kriteria' => 'Laporan/permintaan/keluhan langsung ditanggapi penyedia barang/jasa'],
            ['urutan' => 3, 'nama_kriteria' => 'Seluruh ruang lingkup pekerjaan diselesaikan sesuai kontrak/SLA'],
            ['urutan' => 4, 'nama_kriteria' => 'Berkomunikasi dengan baik tentang pelayanan/pekerjaan penyedia barang/jasa'],
        ];

        foreach ($criteria as $row) {
            DB::table('smki_evaluasi_kriterias')->updateOrInsert(
                ['urutan' => $row['urutan']],
                [...$row, 'created_at' => $now, 'updated_at' => $now],
            );
        }

        $scales = [
            ['label' => 'Baik', 'skor' => 100],
            ['label' => 'Sedang', 'skor' => 60],
            ['label' => 'Kurang', 'skor' => 10],
        ];

        foreach ($scales as $row) {
            DB::table('smki_skala_nilais')->updateOrInsert(
                ['label' => $row['label']],
                [...$row, 'updated_at' => $now, 'created_at' => $now],
            );
        }

        $recommendations = [
            [
                'kategori' => 'Kurang memuaskan',
                'nilai_min' => 0,
                'nilai_max' => 280,
                'keterangan' => 'Kontrak tidak diperpanjang',
            ],
            [
                'kategori' => 'Cukup memuaskan',
                'nilai_min' => 281,
                'nilai_max' => 360,
                'keterangan' => 'Kontrak dapat diperpanjang dengan komitmen perbaikan',
            ],
            [
                'kategori' => 'Memuaskan',
                'nilai_min' => 361,
                'nilai_max' => 400,
                'keterangan' => 'Kontrak dapat diperpanjang',
            ],
        ];

        foreach ($recommendations as $row) {
            DB::table('smki_rekomendasi_evaluasis')->updateOrInsert(
                ['nilai_min' => $row['nilai_min'], 'nilai_max' => $row['nilai_max']],
                [...$row, 'created_at' => $now, 'updated_at' => $now],
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('smki_evaluasi_penyedia_logs');
        Schema::dropIfExists('smki_evaluasi_penyedia_penilaians');
        Schema::dropIfExists('smki_evaluasi_penyedias');
        Schema::dropIfExists('smki_penyedia_jasas');
        Schema::dropIfExists('smki_rekomendasi_evaluasis');
        Schema::dropIfExists('smki_skala_nilais');
        Schema::dropIfExists('smki_evaluasi_kriterias');
    }
};
