<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SmkiAsetSdmPelatihan extends Model
{
    protected $table = 'smki_aset_sdm_pelatihans';

    protected $fillable = ['aset_sdm_id', 'nama_pelatihan', 'tahun'];

    protected $casts = [
        'tahun' => 'integer',
    ];

    public function asetSdm()
    {
        return $this->belongsTo(SmkiAsetSdm::class, 'aset_sdm_id');
    }
}