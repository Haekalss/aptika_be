<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Revisi tabel smki_perubahan_its (tanpa menghapus data yang sudah ada):
     *  - unit_kerja (teks)  -> unit_kerja_id (FK ke master_unit_kerja.id_unit_kerja)
     *  - + judul perubahan
     *  - biaya_perubahan (teks) -> estimasi_biaya (angka rupiah)
     *  - waktu_perubahan (teks) -> tanggal_perubahan + jam_mulai + jam_selesai
     *  - No. RFC lama (RFC-2026-0001) diubah ke format FR-015/001
     */
    public function up(): void
    {
        Schema::table('smki_perubahan_its', function (Blueprint $table) {
            $table->string('judul', 255)->nullable()->after('status');

            $table->unsignedBigInteger('unit_kerja_id')->nullable()->after('pemohon');
            $table->foreign('unit_kerja_id')
                ->references('id_unit_kerja')
                ->on('master_unit_kerja')
                ->restrictOnDelete();

            $table->date('tanggal_perubahan')->nullable();
            $table->time('jam_mulai')->nullable();
            $table->time('jam_selesai')->nullable();
            $table->decimal('estimasi_biaya', 15, 2)->nullable();
        });

        // Pindahkan data lama: nama unit kerja -> id master, teks biaya -> angka
        $unit = DB::table('master_unit_kerja')->pluck('id_unit_kerja', 'nama_unit');

        foreach (DB::table('smki_perubahan_its')->get(['id', 'unit_kerja', 'biaya_perubahan']) as $row) {
            $update = [];

            $id = $unit[trim((string) $row->unit_kerja)] ?? null;
            if ($id) {
                $update['unit_kerja_id'] = $id;
            }

            $digits = preg_replace('/\D/', '', (string) $row->biaya_perubahan);
            if ($digits !== '' && strlen($digits) <= 13) {
                $update['estimasi_biaya'] = (int) $digits;
            }

            if ($update) {
                DB::table('smki_perubahan_its')->where('id', $row->id)->update($update);
            }
        }

        // Samakan format No. RFC dengan desain: FR-015/001
        $urut = 0;
        foreach (DB::table('smki_perubahan_its')->where('no_rfc', 'like', 'RFC-%')->orderBy('id')->pluck('id') as $id) {
            $urut++;
            DB::table('smki_perubahan_its')->where('id', $id)->update([
                'no_rfc' => 'FR-015/' . str_pad((string) $urut, 3, '0', STR_PAD_LEFT),
            ]);
        }

        Schema::table('smki_perubahan_its', function (Blueprint $table) {
            $table->dropColumn(['unit_kerja', 'biaya_perubahan', 'waktu_perubahan']);
        });
    }

    public function down(): void
    {
        Schema::table('smki_perubahan_its', function (Blueprint $table) {
            $table->string('unit_kerja', 255)->nullable();
            $table->text('biaya_perubahan')->nullable();
            $table->text('waktu_perubahan')->nullable();
        });

        $unit = DB::table('master_unit_kerja')->pluck('nama_unit', 'id_unit_kerja');
        foreach (DB::table('smki_perubahan_its')->get(['id', 'unit_kerja_id', 'estimasi_biaya']) as $row) {
            DB::table('smki_perubahan_its')->where('id', $row->id)->update([
                'unit_kerja'      => $unit[$row->unit_kerja_id] ?? null,
                'biaya_perubahan' => $row->estimasi_biaya !== null ? (string) (int) $row->estimasi_biaya : null,
            ]);
        }

        Schema::table('smki_perubahan_its', function (Blueprint $table) {
            $table->dropForeign(['unit_kerja_id']);
            $table->dropColumn(['judul', 'unit_kerja_id', 'tanggal_perubahan', 'jam_mulai', 'jam_selesai', 'estimasi_biaya']);
        });
    }
};
