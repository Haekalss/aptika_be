<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DetailScreening extends Model
{
    protected $table = 'detail_screening';
    protected $primaryKey = 'id_detail';

    protected $fillable = [
        'id_screening',
        'id_item',
        'status',
        'keterangan',
        'kesimpulan',
        'catatan',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(ItemScreening::class, 'id_item', 'id_item');
    }

    public function screening(): BelongsTo
    {
        return $this->belongsTo(ScreeningKaryawan::class, 'id_screening', 'id_screening');
    }
}
