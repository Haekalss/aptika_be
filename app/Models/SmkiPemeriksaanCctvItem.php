<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmkiPemeriksaanCctvItem extends Model
{
    protected $table = 'smki_pemeriksaan_cctv_items';

    protected $fillable = [
        'pemeriksaan_cctv_id',
        'kode_cctv',
        'nama_cctv',
        'lokasi',
        'fungsi',
        'rekaman',
        'obstacle',
        'keterangan',
        'tindak_lanjut',
        'petugas',
    ];

    public function pemeriksaan(): BelongsTo
    {
        return $this->belongsTo(SmkiPemeriksaanCctv::class, 'pemeriksaan_cctv_id');
    }
}
