<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Master Aset Data Center (Jika belum ada)
        if (!Schema::hasTable('data_center_assets')) {
            Schema::create('data_center_assets', function (Blueprint $table) {
                $table->id();
                $table->string('kode_aset', 100)->unique(); // Contoh: DC-SRV-001
                $table->string('nama_aset'); // Contoh: Server Dell PowerEdge R740
                $table->string('kategori')->nullable(); // Server, Switch, UPS, AC Precision, Storage, Router
                $table->string('lokasi_rak')->nullable(); // Contoh: Data Center Utama, Rak A-01 / U12
                $table->string('status_operasional')->default('Operasional'); // Operasional, Rusak, Maintenance
                $table->string('penanggung_jawab_default')->nullable(); // Contoh: Andi Ramadhan
                $table->unsignedBigInteger('penanggung_jawab_id')->nullable(); // FK to users if any
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('penanggung_jawab_id')->references('id')->on('users')->nullOnDelete();
            });
        }

        // 2. Transaksi Utama Laporan Kerusakan
        if (!Schema::hasTable('laporan_kerusakan_data_centers')) {
            Schema::create('laporan_kerusakan_data_centers', function (Blueprint $table) {
                $table->id();
                $table->string('nomor_laporan', 50)->unique(); // Contoh: LKR-202610-0001
                $table->date('tanggal'); // 2026-10-16
                $table->string('jam', 20)->default('09:30 WIB'); // 09.30 WIB

                // Relasi Aset Data Center
                $table->unsignedBigInteger('aset_id')->nullable()->index();
                $table->string('nama_aset'); // Disimpan juga flat untuk snapshot
                $table->string('kode_aset')->nullable();
                $table->string('lokasi_rak')->nullable();

                // Detail Kerusakan
                $table->text('jenis_kerusakan'); // Uraian gejala/kondisi aset
                $table->text('penyebab')->nullable(); // Dugaan penyebab

                // Tindakan & Penugasan
                $table->text('uraian_tindakan')->nullable(); // Tindakan yang dilakukan teknisi
                $table->date('target_selesai')->nullable();
                $table->timestamp('tanggal_selesai_aktual')->nullable();

                // Person In Charge & Actors
                $table->unsignedBigInteger('pelapor_id')->nullable()->index(); // Teknisi yang membuat
                $table->string('pelapor_nama')->nullable();
                $table->unsignedBigInteger('penanggung_jawab_id')->nullable()->index(); // Teknisi bertugas
                $table->string('penanggung_jawab_nama')->nullable();
                $table->unsignedBigInteger('supervisor_id')->nullable()->index(); // Supervisor yang validasi
                $table->string('supervisor_nama')->nullable();

                // Status Workflow: 'Baru', 'Diproses', 'Selesai', 'Closed'
                $table->string('status', 30)->default('Baru')->index();
                $table->text('catatan_validasi')->nullable(); // Catatan supervisor jika ditolak/disetujui
                $table->timestamp('validated_at')->nullable();

                $table->timestamps();
                $table->softDeletes();

                $table->foreign('aset_id')->references('id')->on('data_center_assets')->nullOnDelete();
                $table->foreign('pelapor_id')->references('id')->on('users')->nullOnDelete();
                $table->foreign('penanggung_jawab_id')->references('id')->on('users')->nullOnDelete();
                $table->foreign('supervisor_id')->references('id')->on('users')->nullOnDelete();
            });
        }

        // 3. Log Riwayat Status / Tindakan
        if (!Schema::hasTable('laporan_kerusakan_histories')) {
            Schema::create('laporan_kerusakan_histories', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('laporan_id')->index();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->string('action', 100); // Create, Update Tindakan, Assign, Submit Selesai, Validasi, Closed
                $table->string('status_from', 50)->nullable();
                $table->string('status_to', 50)->nullable();
                $table->text('catatan')->nullable();
                $table->timestamps();

                $table->foreign('laporan_id')->references('id')->on('laporan_kerusakan_data_centers')->cascadeOnDelete();
                $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('laporan_kerusakan_histories');
        Schema::dropIfExists('laporan_kerusakan_data_centers');
        Schema::dropIfExists('data_center_assets');
    }
};
