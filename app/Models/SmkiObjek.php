<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SmkiObjek extends Model
{
    protected $table = 'smki_objeks';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function jenis(): BelongsTo
    {
        return $this->belongsTo(SmkiJenisKonfigurasi::class, 'jenis_konfigurasi_id');
    }

    public function nilaiStandars(): HasMany
    {
        return $this->hasMany(SmkiNilaiStandar::class, 'objek_id');
    }

    public function scopeAktif($query): void
    {
        $query->where('is_active', true);
    }
}
