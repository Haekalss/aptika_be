<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmkiDrpEvaluasiTim extends Model
{
    protected $table = 'smki_drp_evaluasi_tims';

    protected $fillable = [
        'drp_evaluasi_id',
        'peran',
        'tanggung_jawab',
        'evaluasi',
        'rekomendasi',
    ];

    public function evaluasi(): BelongsTo
    {
        return $this->belongsTo(SmkiDrpEvaluasi::class, 'drp_evaluasi_id');
    }
}
