<?php

namespace App\Models;

use App\Traits\BelongsToBidang;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SmkiDrpEvaluasi extends Model
{
    use BelongsToBidang, SoftDeletes;

    protected $table = 'smki_drp_evaluasis';

    /**
     * Kriteria Check List Pengujian BCP (bagian B formulir FR-039): kunci => [kategori, kriteria].
     */
    public const CHECKLIST = [
        'kelengkapan_1' => ['Kelengkapan (Completeness)', 'Setiap langkah dalam rencana dilakukan semua (langkah tidak hilang)'],
        'kelengkapan_2' => ['Kelengkapan (Completeness)', 'Peserta tidak perlu membuat asumsi yang bukan bagian dari rencana'],
        'kelengkapan_3' => ['Kelengkapan (Completeness)', 'Informasi yang diperlukan di dalam rencana tersedia (nomor telepon, alamat, lokasi)'],
        'kejelasan_1'   => ['Kejelasan (Clarity)', 'Peserta memahami arah atau langkah-langkah yang diberikan dalam rencana, tidak memerlukan klarifikasi dari koordinator'],
        'kejelasan_2'   => ['Kejelasan (Clarity)', 'Tujuan untuk setiap bagian sudah jelas'],
        'kejelasan_3'   => ['Kejelasan (Clarity)', 'Rencana organisasi jelas dan logis, peserta tidak "tersesat" dalam rencana'],
        'eksekusi_1'    => ['Kemungkinan dapat dieksekusi (Executability)', 'Rencana memiliki titik awal yang jelas'],
        'eksekusi_2'    => ['Kemungkinan dapat dieksekusi (Executability)', 'Rencana memiliki titik akhir yang jelas'],
        'eksekusi_3'    => ['Kemungkinan dapat dieksekusi (Executability)', 'Rencana tertulis sedemikian rupa sehingga ada aliran logis dari awal sampai akhir'],
        'eksekusi_4'    => ['Kemungkinan dapat dieksekusi (Executability)', 'Recovery Time Objectives (RTO) yang realistis'],
    ];

    protected $fillable = [
        'bidang_id',
        'user_id',
        'tanggal_pengujian',
        'waktu_mulai',
        'waktu_selesai',
        'tujuan',
        'cakupan',
        'tipe_pengujian',
        'asumsi_bencana',
        'asumsi_sumber_daya',
        'checklist',
        'lampiran',
        'dibuat_oleh_nama',
        'dibuat_oleh_tanggal',
        'diperiksa_oleh_nama',
        'diperiksa_oleh_tanggal',
        'disetujui_oleh_nama',
        'disetujui_oleh_tanggal',
    ];

    protected $casts = [
        'tanggal_pengujian'      => 'date:Y-m-d',
        'dibuat_oleh_tanggal'    => 'date:Y-m-d',
        'diperiksa_oleh_tanggal' => 'date:Y-m-d',
        'disetujui_oleh_tanggal' => 'date:Y-m-d',
        'checklist'              => 'array',
    ];

    public function waktu(): HasMany
    {
        return $this->hasMany(SmkiDrpEvaluasiWaktu::class, 'drp_evaluasi_id')->orderBy('id');
    }

    public function proses(): HasMany
    {
        return $this->hasMany(SmkiDrpEvaluasiProses::class, 'drp_evaluasi_id')->orderBy('id');
    }

    public function tim(): HasMany
    {
        return $this->hasMany(SmkiDrpEvaluasiTim::class, 'drp_evaluasi_id')->orderBy('id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
