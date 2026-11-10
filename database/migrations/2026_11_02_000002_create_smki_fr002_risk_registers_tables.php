<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Header Dokumen FR-002
        if (!Schema::hasTable('smki_fr002_headers')) {
            Schema::create('smki_fr002_headers', function (Blueprint $table) {
                $table->id();
                $table->year('tahun_periode')->default(2023);
                $table->string('ruang_lingkup', 150)->default('Fisik Data Center');
                $table->string('no_revisi', 50)->default('4.0');
                $table->date('tanggal_dokumen')->nullable();
                $table->string('klasifikasi', 50)->default('Internal');
                $table->string('jabatan_pengesah', 100)->default('Kepala Bidang Aplikasi Informatika');
                $table->string('nama_pengesah', 150)->nullable();
                $table->string('nip_pengesah', 50)->nullable();
                $table->timestamps();
            });
        }

        // 2. Kategori Aset (A sampai E)
        if (!Schema::hasTable('smki_fr002_kategori_asets')) {
            Schema::create('smki_fr002_kategori_asets', function (Blueprint $table) {
                $table->string('kode_kategori', 5)->primary(); // A, B, C, D, E
                $table->string('nama_kategori_aset', 150);
                $table->text('deskripsi')->nullable();
                $table->integer('urutan')->default(1);
                $table->timestamps();
            });
        }

        $defaultKategoris = [
            ['kode_kategori' => 'A', 'nama_kategori_aset' => 'INFORMASI (Cetak/Elektronik)', 'deskripsi' => 'Aset informasi baik cetak maupun elektronik', 'urutan' => 1],
            ['kode_kategori' => 'B', 'nama_kategori_aset' => 'SOFTWARE (Aplikasi, S/W sistem, development tools)', 'deskripsi' => 'Aplikasi, operating system, tools dan software pendukung', 'urutan' => 2],
            ['kode_kategori' => 'C', 'nama_kategori_aset' => 'INFRASTRUKTUR HARDWARE DAN NETWORK', 'deskripsi' => 'Perangkat keras server, switch, router, storage, dan jaringan', 'urutan' => 3],
            ['kode_kategori' => 'D', 'nama_kategori_aset' => 'FASILITAS DAN SARANA PENDUKUNG (UTILITY)', 'deskripsi' => 'Genset, UPS, AC Precision, CCTV, APAR, dan utilitas DC', 'urutan' => 4],
            ['kode_kategori' => 'E', 'nama_kategori_aset' => 'SUMBER DAYA MANUSIA DAN PIHAK KETIGA', 'deskripsi' => 'Pegawai, tenaga ahli, vendor, dan pihak ketiga', 'urutan' => 5],
        ];

        foreach ($defaultKategoris as $item) {
            DB::table('smki_fr002_kategori_asets')->insertOrIgnore(array_merge($item, [
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        }

        // 3. Kontrol Annex ISO 27001
        if (!Schema::hasTable('smki_fr002_kontrol_annex_isos')) {
            Schema::create('smki_fr002_kontrol_annex_isos', function (Blueprint $table) {
                $table->id();
                $table->string('kode_annex', 50)->unique();
                $table->string('nama_kontrol_iso', 255);
                $table->string('kategori_tema', 50)->default('Organizational'); // Organizational, People, Physical, Technological
                $table->timestamps();
            });
        }

        $defaultAnnex = [
            ['kode_annex' => 'A5.1', 'nama_kontrol_iso' => 'A5.1 Kebijakan untuk keamanan informasi', 'kategori_tema' => 'Organizational'],
            ['kode_annex' => 'A5.2', 'nama_kontrol_iso' => 'A5.2 Peran dan tanggung jawab keamanan informasi', 'kategori_tema' => 'Organizational'],
            ['kode_annex' => 'A5.9', 'nama_kontrol_iso' => 'A5.9 Inventarisasi informasi dan aset terkait lainnya', 'kategori_tema' => 'Organizational'],
            ['kode_annex' => 'A5.15', 'nama_kontrol_iso' => 'A5.15 Pengendalian akses', 'kategori_tema' => 'Organizational'],
            ['kode_annex' => 'A5.24', 'nama_kontrol_iso' => 'A5.24 Perencanaan dan kesiapsiagaan manajemen insiden', 'kategori_tema' => 'Organizational'],
            ['kode_annex' => 'A7.1', 'nama_kontrol_iso' => 'A7.1 Perimeter keamanan fisik', 'kategori_tema' => 'Physical'],
            ['kode_annex' => 'A7.2', 'nama_kontrol_iso' => 'A7.2 Masuk fisik', 'kategori_tema' => 'Physical'],
            ['kode_annex' => 'A7.4', 'nama_kontrol_iso' => 'A7.4 Pemantauan keamanan fisik', 'kategori_tema' => 'Physical'],
            ['kode_annex' => 'A8.1', 'nama_kontrol_iso' => 'A8.1 Perangkat titik akhir pengguna (Endpoint)', 'kategori_tema' => 'Technological'],
            ['kode_annex' => 'A8.8', 'nama_kontrol_iso' => 'A8.8 Manajemen kerentanan teknis', 'kategori_tema' => 'Technological'],
            ['kode_annex' => 'A8.16', 'nama_kontrol_iso' => 'A8.16 Kegiatan Pemantauan (Monitoring & SIEM)', 'kategori_tema' => 'Technological'],
            ['kode_annex' => 'A8.20', 'nama_kontrol_iso' => 'A8.20 Keamanan jaringan', 'kategori_tema' => 'Technological'],
            ['kode_annex' => 'A8.34', 'nama_kontrol_iso' => 'A8.34 Perlindungan Sistem Informasi Selama Pengujian Audit', 'kategori_tema' => 'Technological'],
        ];

        foreach ($defaultAnnex as $item) {
            DB::table('smki_fr002_kontrol_annex_isos')->insertOrIgnore(array_merge($item, [
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        }

        // 4. Data Risiko FR-002
        if (!Schema::hasTable('smki_fr002_risikos')) {
            Schema::create('smki_fr002_risikos', function (Blueprint $table) {
                $table->id();
                $table->foreignId('header_id')->nullable()->constrained('smki_fr002_headers')->nullOnDelete();
                $table->string('kode_kategori', 5); // FK to smki_fr002_kategori_asets
                $table->string('kode_annex', 50)->nullable();
                $table->string('nama_aset_proses', 255);
                $table->text('kerawanan_vulnerability')->nullable();
                $table->text('ancaman_threat')->nullable();
                $table->string('kategori_cia', 20)->default('C,I,A'); // e.g. "I,A", "C,I,A"
                $table->text('uraian_dampak')->nullable();

                // Inherent Risk
                $table->unsignedTinyInteger('inherent_likelihood')->default(1);
                $table->unsignedTinyInteger('inherent_impact')->default(1);
                $table->unsignedInteger('inherent_score')->default(1);
                $table->string('inherent_kategori', 50)->default('Rendah'); // Rendah, Menengah, Tinggi, Sangat Tinggi, Ekstrim

                // Existing Control & Current Risk
                $table->text('existing_control')->nullable();
                $table->string('referensi_dokumen', 255)->nullable();
                $table->string('kontrol_annex_iso', 255)->nullable();
                $table->unsignedTinyInteger('current_likelihood')->default(1);
                $table->unsignedTinyInteger('current_impact')->default(1);
                $table->unsignedInteger('current_score')->default(1);
                $table->string('current_kategori', 50)->default('Rendah');
                $table->string('status_risiko_current', 50)->default('Dapat Diterima'); // Dapat Diterima, Masalah Kecil, Masalah Besar, Masalah Sangat Besar

                // Residual Risk
                $table->unsignedTinyInteger('residual_likelihood')->default(1);
                $table->unsignedTinyInteger('residual_impact')->default(1);
                $table->unsignedInteger('residual_score')->default(1);
                $table->string('residual_kategori', 50)->default('Rendah');
                $table->string('status_risiko_residual', 50)->default('Dapat Diterima');

                $table->boolean('is_deleted')->default(false);
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->foreign('kode_kategori')->references('kode_kategori')->on('smki_fr002_kategori_asets')->cascadeOnUpdate();
            });
        }

        // 5. Risk Treatment Plan (Detail Mitigasi)
        if (!Schema::hasTable('smki_fr002_risk_treatment_plans')) {
            Schema::create('smki_fr002_risk_treatment_plans', function (Blueprint $table) {
                $table->id();
                $table->foreignId('risiko_id')->constrained('smki_fr002_risikos')->cascadeOnDelete();
                $table->string('opsi_penanggulangan', 255)->nullable(); // Mitigate, Accept, Transfer, Avoid
                $table->text('uraian_rencana')->nullable();
                $table->date('target_selesai')->nullable();
                $table->string('pic_penanggung_jawab', 150)->nullable();
                $table->string('prioritas', 50)->default('Menengah'); // Rendah, Menengah, Tinggi, Sangat Tinggi
                $table->string('ownership', 150)->default('Bidang Aplikasi Informatika');
                $table->enum('status_monitoring', ['Open', 'On Progress', 'Closed'])->default('Open');
                $table->text('keterangan')->nullable();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Safe down: drop in order
        Schema::dropIfExists('smki_fr002_risk_treatment_plans');
        Schema::dropIfExists('smki_fr002_risikos');
        Schema::dropIfExists('smki_fr002_kontrol_annex_isos');
        Schema::dropIfExists('smki_fr002_kategori_asets');
        Schema::dropIfExists('smki_fr002_headers');
    }
};
