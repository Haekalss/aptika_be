<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmkiReviewLogItem extends Model
{
    protected $table = 'smki_review_log_items';

    protected $fillable = [
        'review_log_id',
        'user_log',
        'ip_address',
        'tanggal',
        'waktu',
        'durasi',
    ];

    protected $casts = [
        'tanggal' => 'date:Y-m-d',
    ];

    public function reviewLog(): BelongsTo
    {
        return $this->belongsTo(SmkiReviewLog::class, 'review_log_id');
    }
}
