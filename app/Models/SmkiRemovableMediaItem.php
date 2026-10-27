<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmkiRemovableMediaItem extends Model
{
    protected $guarded = ['id', 'report_id'];

    protected function casts(): array
    {
        return ['jumlah' => 'integer', 'tahun_pengadaan' => 'integer', 'urutan' => 'integer'];
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(SmkiRemovableMediaReport::class, 'report_id');
    }
}
