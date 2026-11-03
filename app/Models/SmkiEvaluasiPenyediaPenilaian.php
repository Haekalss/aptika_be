<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmkiEvaluasiPenyediaPenilaian extends Model
{
    protected $table = 'smki_evaluasi_penyedia_penilaians';

    protected $fillable = ['evaluasi_id', 'kriteria_id', 'skala_id', 'skor'];

    protected $casts = ['skor' => 'integer'];

    public function evaluasi(): BelongsTo
    {
        return $this->belongsTo(SmkiEvaluasiPenyedia::class, 'evaluasi_id');
    }

    public function kriteria(): BelongsTo
    {
        return $this->belongsTo(SmkiEvaluasiKriteria::class, 'kriteria_id');
    }

    public function skala(): BelongsTo
    {
        return $this->belongsTo(SmkiSkalaNilai::class, 'skala_id');
    }
}
