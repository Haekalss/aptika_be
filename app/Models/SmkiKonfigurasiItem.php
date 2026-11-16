<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmkiKonfigurasiItem extends Model
{
    protected $table = 'smki_konfigurasi_items';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_standar' => 'boolean',
            'nilai_standar_id' => 'integer',
        ];
    }

    public function pemantauanObjek(): BelongsTo
    {
        return $this->belongsTo(SmkiPemantauanObjek::class, 'pemantauan_objek_id');
    }

    public function nilaiStandar(): BelongsTo
    {
        return $this->belongsTo(SmkiNilaiStandar::class, 'nilai_standar_id');
    }
}
