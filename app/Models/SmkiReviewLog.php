<?php

namespace App\Models;

use App\Traits\BelongsToBidang;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SmkiReviewLog extends Model
{
    use BelongsToBidang, SoftDeletes;

    protected $table = 'smki_review_logs';

    protected $fillable = [
        'bidang_id',
        'user_id',
        'tanggal_review',
        'periode_mulai',
        'periode_selesai',
        'obyek_review',
        'pelaksana_review',
        'tindak_lanjut',
        'dibuat_oleh_nama',
        'dibuat_oleh_nip',
        'mengetahui_nama',
        'mengetahui_nip',
        'ditandatangani_di',
        'tanggal_ttd',
    ];

    protected $casts = [
        'tanggal_review'  => 'date:Y-m-d',
        'periode_mulai'   => 'date:Y-m-d',
        'periode_selesai' => 'date:Y-m-d',
        'tanggal_ttd'     => 'date:Y-m-d',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(SmkiReviewLogItem::class, 'review_log_id')->orderBy('id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
