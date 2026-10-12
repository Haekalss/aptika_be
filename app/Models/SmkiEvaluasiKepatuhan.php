<?php

namespace App\Models;

use App\Traits\BelongsToBidang;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SmkiEvaluasiKepatuhan extends Model
{
    use BelongsToBidang, SoftDeletes;

    protected $table = 'smki_evaluasi_kepatuhans';

    protected $fillable = [
        'bidang_id',
        'user_id',
        'tanggal_evaluasi',
        'ditandatangani_di',
        'tanggal_ttd',
    ];

    protected $casts = [
        'tanggal_evaluasi' => 'date:Y-m-d',
        'tanggal_ttd'      => 'date:Y-m-d',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(SmkiEvaluasiKepatuhanItem::class, 'evaluasi_kepatuhan_id')->orderBy('id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
