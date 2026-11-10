<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tambah kolom penanda tangan (nama/NIP/tempat/tanggal TTD) untuk
     * integrasi spesimen TTE pada modul FR-014, FR-018, FR-039, FR-011,
     * FR-012, FR-037, FR-044, dan FR-010. Semua nullable agar data histori tetap valid.
     */
    public function up(): void
    {
        Schema::table('berita_acara', function (Blueprint $table) {
            if (!Schema::hasColumn('berita_acara', 'nip_pelaksana')) {
                $table->string('nip_pelaksana', 50)->nullable()->after('nama_pelaksana');
            }
            if (!Schema::hasColumn('berita_acara', 'nip_diketahui')) {
                $table->string('nip_diketahui', 50)->nullable()->after('nama_diketahui');
            }
        });

        Schema::table('hak_akses_ti', function (Blueprint $table) {
            foreach ([
                'atasan_nama' => 255, 'atasan_nip' => 50,
                'penanggung_jawab_nama' => 255, 'penanggung_jawab_nip' => 50,
                'agen_nama' => 255, 'agen_nip' => 50,
            ] as $col => $len) {
                if (!Schema::hasColumn('hak_akses_ti', $col)) {
                    $table->string($col, $len)->nullable()->after('nama_pemohon');
                }
            }
        });

        Schema::table('smki_drp_evaluasis', function (Blueprint $table) {
            foreach (['dibuat_oleh_nip', 'diperiksa_oleh_nip', 'disetujui_oleh_nip'] as $col) {
                if (!Schema::hasColumn('smki_drp_evaluasis', $col)) {
                    $table->string($col, 50)->nullable();
                }
            }
        });

        Schema::table('smki_notulens', function (Blueprint $table) {
            if (!Schema::hasColumn('smki_notulens', 'notulis_nip')) {
                $table->string('notulis_nip', 50)->nullable()->after('notulis');
            }
            if (!Schema::hasColumn('smki_notulens', 'koordinator_nip')) {
                $table->string('koordinator_nip', 50)->nullable()->after('koordinator_smki');
            }
        });

        Schema::table('screening_karyawan', function (Blueprint $table) {
            if (!Schema::hasColumn('screening_karyawan', 'nama_verifikator')) {
                $table->string('nama_verifikator', 255)->nullable();
            }
            if (!Schema::hasColumn('screening_karyawan', 'nip_verifikator')) {
                $table->string('nip_verifikator', 50)->nullable();
            }
            if (!Schema::hasColumn('screening_karyawan', 'ditandatangani_di')) {
                $table->string('ditandatangani_di', 255)->nullable();
            }
            if (!Schema::hasColumn('screening_karyawan', 'tanggal_ttd')) {
                $table->date('tanggal_ttd')->nullable();
            }
        });

        Schema::table('smki_pemeriksaan_cctvs', function (Blueprint $table) {
            if (!Schema::hasColumn('smki_pemeriksaan_cctvs', 'nip_pemeriksa')) {
                $table->string('nip_pemeriksa', 50)->nullable()->after('nama_pemeriksa');
            }
            if (!Schema::hasColumn('smki_pemeriksaan_cctvs', 'ditandatangani_di')) {
                $table->string('ditandatangani_di', 255)->nullable();
            }
            if (!Schema::hasColumn('smki_pemeriksaan_cctvs', 'tanggal_ttd')) {
                $table->date('tanggal_ttd')->nullable();
            }
        });

        Schema::table('smki_evaluasi_kepatuhans', function (Blueprint $table) {
            if (!Schema::hasColumn('smki_evaluasi_kepatuhans', 'nama_penandatangan')) {
                $table->string('nama_penandatangan', 255)->nullable()->after('tanggal_ttd');
            }
            if (!Schema::hasColumn('smki_evaluasi_kepatuhans', 'nip_penandatangan')) {
                $table->string('nip_penandatangan', 50)->nullable()->after('nama_penandatangan');
            }
        });

        Schema::table('smki_komunikasi_headers', function (Blueprint $table) {
            foreach ([
                'disusun_nama' => 'string', 'disusun_nip' => 'string',
                'mengetahui_nama' => 'string', 'mengetahui_nip' => 'string',
                'kota' => 'string',
            ] as $col => $type) {
                if (!Schema::hasColumn('smki_komunikasi_headers', $col)) {
                    $table->string($col, $col === 'kota' ? 255 : ($type === 'string' && str_ends_with($col, '_nip') ? 50 : 255))->nullable();
                }
            }
            if (!Schema::hasColumn('smki_komunikasi_headers', 'tanggal_ttd')) {
                $table->date('tanggal_ttd')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('berita_acara', function (Blueprint $table) {
            $table->dropColumn(['nip_pelaksana', 'nip_diketahui']);
        });
        Schema::table('hak_akses_ti', function (Blueprint $table) {
            $table->dropColumn(['atasan_nama', 'atasan_nip', 'penanggung_jawab_nama', 'penanggung_jawab_nip', 'agen_nama', 'agen_nip']);
        });
        Schema::table('smki_drp_evaluasis', function (Blueprint $table) {
            $table->dropColumn(['dibuat_oleh_nip', 'diperiksa_oleh_nip', 'disetujui_oleh_nip']);
        });
        Schema::table('smki_notulens', function (Blueprint $table) {
            $table->dropColumn(['notulis_nip', 'koordinator_nip']);
        });
        Schema::table('screening_karyawan', function (Blueprint $table) {
            $table->dropColumn(['nama_verifikator', 'nip_verifikator', 'ditandatangani_di', 'tanggal_ttd']);
        });
        Schema::table('smki_pemeriksaan_cctvs', function (Blueprint $table) {
            $table->dropColumn(['nip_pemeriksa', 'ditandatangani_di', 'tanggal_ttd']);
        });
        Schema::table('smki_evaluasi_kepatuhans', function (Blueprint $table) {
            $table->dropColumn(['nama_penandatangan', 'nip_penandatangan']);
        });
        Schema::table('smki_komunikasi_headers', function (Blueprint $table) {
            $table->dropColumn(['disusun_nama', 'disusun_nip', 'mengetahui_nama', 'mengetahui_nip', 'kota', 'tanggal_ttd']);
        });
    }
};
