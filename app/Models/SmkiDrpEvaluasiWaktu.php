<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmkiDrpEvaluasiWaktu extends Model
{
    protected $table = 'smki_drp_evaluasi_waktus';

    protected $fillable = [
        'drp_evaluasi_id',
        'sistem_ti',
        'rto_ideal',
        'pencapaian_rto',
        'kendala',
    ];

    public function evaluasi(): BelongsTo
    {
        return $this->belongsTo(SmkiDrpEvaluasi::class, 'drp_evaluasi_id');
    }
}
