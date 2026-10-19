<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmkiReviuCctvItem extends Model
{
    protected $table = 'smki_reviu_cctv_items';

    protected $fillable = [
        'reviu_cctv_id',
        'uraian_reviu',
        'tindak_lanjut',
        'target_selesai',
        'keterangan',
    ];

    protected $casts = [
        'target_selesai' => 'date:Y-m-d',
    ];

    public function reviu(): BelongsTo
    {
        return $this->belongsTo(SmkiReviuCctv::class, 'reviu_cctv_id');
    }
}
