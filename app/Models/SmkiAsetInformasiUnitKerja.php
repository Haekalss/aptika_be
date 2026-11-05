<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmkiAsetInformasiUnitKerja extends Model
{
    protected $table = 'smki_aset_informasi_unit_kerjas';

    protected $fillable = [
        'formulir_id',
        'no_urut',
        'kode_aset',
        'nama_aset',
        'klasifikasi',
        'tahun_pembuatan',
        'tahun_update',
        'deskripsi',
        'format_penyimpanan',
        'masa_simpan',
        'lokasi_penyimpanan',
        'pemilik_aset',
        'keterangan',
    ];

    public function formulir(): BelongsTo
    {
        return $this->belongsTo(SmkiFormulirAsetInformasi::class, 'formulir_id');
    }
}
