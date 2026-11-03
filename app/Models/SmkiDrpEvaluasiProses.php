<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmkiDrpEvaluasiProses extends Model
{
    protected $table = 'smki_drp_evaluasi_proses';

    protected $fillable = [
        'drp_evaluasi_id',
        'proses',
        'aktivitas',
        'hasil_evaluasi',
        'rekomendasi',
        'pic',
    ];

    public function evaluasi(): BelongsTo
    {
        return $this->belongsTo(SmkiDrpEvaluasi::class, 'drp_evaluasi_id');
    }
}
