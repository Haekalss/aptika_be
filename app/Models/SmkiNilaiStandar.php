<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmkiNilaiStandar extends Model
{
    protected $table = 'smki_nilai_standars';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function objek(): BelongsTo
    {
        return $this->belongsTo(SmkiObjek::class, 'objek_id');
    }

    public function scopeAktif($query): void
    {
        $query->where('is_active', true);
    }
}
