<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmkiEvaluasiPenyediaLog extends Model
{
    protected $table = 'smki_evaluasi_penyedia_logs';

    public const CREATED_AT = 'created_at';
    public const UPDATED_AT = null;

    protected $fillable = [
        'evaluasi_id',
        'aksi',
        'siklus',
        'catatan',
        'total_nilai',
        'rekomendasi_id',
        'user_id',
        'penilaian_snapshot',
        'tte_snapshot',
    ];

    protected $casts = [
        'siklus' => 'integer',
        'total_nilai' => 'integer',
        'penilaian_snapshot' => 'array',
        'tte_snapshot' => 'array',
        'created_at' => 'datetime',
    ];

    public function evaluasi(): BelongsTo
    {
        return $this->belongsTo(SmkiEvaluasiPenyedia::class, 'evaluasi_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function rekomendasi(): BelongsTo
    {
        return $this->belongsTo(SmkiRekomendasiEvaluasi::class, 'rekomendasi_id');
    }
}
