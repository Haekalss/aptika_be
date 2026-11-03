<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmkiAsetInformasiIntangible extends Model
{
    protected $table = 'smki_aset_informasi_intangibles';

    protected $fillable = [
        'formulir_id',
        'no_urut',
        'kode_aset',
        'layanan',
        'nama_aset',
        'sub_klasifikasi',
        'pemilik_aset',
        'kerahasiaan',
        'integritas',
        'ketersediaan',
        'nilai',
        'keterangan',
    ];

    protected $casts = [
        'kerahasiaan' => 'integer',
        'integritas' => 'integer',
        'ketersediaan' => 'integer',
        'nilai' => 'integer',
    ];

    public function formulir(): BelongsTo
    {
        return $this->belongsTo(SmkiFormulirAsetInformasi::class, 'formulir_id');
    }
}
