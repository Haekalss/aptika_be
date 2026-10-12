<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmkiEvaluasiKepatuhanItem extends Model
{
    protected $table = 'smki_evaluasi_kepatuhan_items';

    protected $fillable = [
        'evaluasi_kepatuhan_id',
        'dokumen_kepatuhan',
        'deskripsi',
        'evaluasi_status',
    ];

    public function evaluasi(): BelongsTo
    {
        return $this->belongsTo(SmkiEvaluasiKepatuhan::class, 'evaluasi_kepatuhan_id');
    }
}
