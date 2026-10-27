<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmkiTindakanPerbaikanItem extends Model
{
    protected $fillable = ['jenis', 'urutan', 'uraian', 'pic_id', 'pic_nama', 'batas_waktu'];

    protected $casts = ['batas_waktu' => 'date:Y-m-d', 'urutan' => 'integer'];

    public function tindakanPerbaikan(): BelongsTo
    {
        return $this->belongsTo(SmkiTindakanPerbaikan::class, 'tindakan_perbaikan_id');
    }
}
