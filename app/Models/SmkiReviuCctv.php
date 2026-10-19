<?php

namespace App\Models;

use App\Traits\BelongsToBidang;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SmkiReviuCctv extends Model
{
    use BelongsToBidang, SoftDeletes;

    protected $table = 'smki_reviu_cctvs';

    protected $fillable = [
        'bidang_id',
        'user_id',
        'tanggal_reviu',
        'periode_mulai',
        'periode_selesai',
        'obyek_reviu',
        'pelaksana_reviu',
        'pengelola_nama',
        'pengelola_nip',
    ];

    protected $casts = [
        'tanggal_reviu'   => 'date:Y-m-d',
        'periode_mulai'   => 'date:Y-m-d',
        'periode_selesai' => 'date:Y-m-d',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(SmkiReviuCctvItem::class, 'reviu_cctv_id')->orderBy('id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
