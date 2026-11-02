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
        if (!Schema::hasTable('smki_ruangan_areas')) {
            Schema::create('smki_ruangan_areas', function (Blueprint $table) {
                $table->id();
                $table->string('kode_ruangan', 50)->nullable();
                $table->string('nama_ruangan', 150);
                $table->string('deskripsi_area', 255)->nullable();
                $table->timestamps();
            });

            // Seed default rooms based on FR-019 form template
            $defaultRuangan = [
                ['kode_ruangan' => 'DC', 'nama_ruangan' => 'Data Center', 'deskripsi_area' => 'Ruang Server & Pusat Data Utama'],
                ['kode_ruangan' => 'NOC', 'nama_ruangan' => 'Ruang NOC', 'deskripsi_area' => 'Network Operations Center'],
                ['kode_ruangan' => 'UPS', 'nama_ruangan' => 'UPS', 'deskripsi_area' => 'Ruang Baterai dan Uninterruptible Power Supply'],
                ['kode_ruangan' => 'GEN-GDG', 'nama_ruangan' => 'Akses Genset Gedung', 'deskripsi_area' => 'Area Genset Utama Gedung Diskominfo'],
                ['kode_ruangan' => 'PNT-UTM', 'nama_ruangan' => 'Pintu Utama', 'deskripsi_area' => 'Akses Gerbang dan Pintu Masuk Terbatas'],
                ['kode_ruangan' => 'SOC', 'nama_ruangan' => 'Ruang SOC', 'deskripsi_area' => 'Security Operations Center (CSIRT Jabar)'],
                ['kode_ruangan' => 'GEN-DC', 'nama_ruangan' => 'Akses Genset DC', 'deskripsi_area' => 'Area Genset Khusus Data Center'],
            ];

            foreach ($defaultRuangan as $r) {
                DB::table('smki_ruangan_areas')->insert(array_merge($r, [
                    'created_at' => now(),
                    'updated_at' => now(),
                ]));
            }
        }

        if (!Schema::hasTable('smki_hak_akses_fisiks')) {
            Schema::create('smki_hak_akses_fisiks', function (Blueprint $table) {
                $table->id();
                $table->string('nomor_dokumen', 100);
                $table->date('tanggal_pengajuan');
                $table->foreignId('pegawai_id')->nullable()->constrained('pegawai')->nullOnDelete();
                $table->string('nama_pemohon', 150);
                $table->string('nip', 50)->nullable();
                $table->string('jabatan', 100)->nullable();
                $table->string('unit_kerja', 150)->nullable();
                $table->string('no_handphone', 50)->nullable();
                $table->string('email', 100)->nullable();
                
                // Detail Hak Akses
                $table->string('jenis_permintaan', 50)->default('Pendaftaran'); // Pendaftaran / Penutupan
                $table->string('sifat_akses', 50)->default('Permanen'); // Permanen / Sementara
                $table->string('waktu_akses', 100)->default('7 x 24 jam'); // 7 x 24 jam / 08.00 – 17.00 / Lainnya
                $table->string('waktu_akses_lainnya', 255)->nullable();
                $table->date('berlaku_sampai')->nullable();
                $table->json('ruangan_akses')->nullable(); // Checklist ruangan
                $table->json('keperluan_alasan')->nullable(); // Checklist alasan
                $table->text('keperluan_lainnya')->nullable();
                $table->string('status_pengajuan', 50)->default('Approved'); // Pending, Approved, Rejected
                $table->text('catatan')->nullable();

                // 4 Penanda Tangan (Spesimen TTE)
                $table->string('pemohon_nama', 150)->nullable();
                $table->string('pemohon_nip', 50)->nullable();
                $table->string('atasan_nama', 150)->nullable();
                $table->string('atasan_nip', 50)->nullable();
                $table->string('penanggung_jawab_nama', 150)->nullable();
                $table->string('penanggung_jawab_nip', 50)->nullable();
                $table->string('agen_nama', 150)->nullable();
                $table->string('agen_nip', 50)->nullable();

                // Kontrol Dokumen ISO 27001 (FR-019 Header)
                $table->string('header_no_dokumen', 100)->default('FR-019//KOM.03.05/SANDIKAMI');
                $table->string('header_no_revisi', 20)->default('1.1');
                $table->date('header_tanggal_berlaku')->nullable();

                // Soft delete & Tracking
                $table->boolean('is_deleted')->default(false);
                $table->foreignId('bidang_id')->nullable()->constrained('bidangs')->nullOnDelete();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['is_deleted', 'created_at']);
                $table->index('nomor_dokumen');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('smki_hak_akses_fisiks');
        Schema::dropIfExists('smki_ruangan_areas');
    }
};
