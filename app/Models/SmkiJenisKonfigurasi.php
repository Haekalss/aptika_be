<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SmkiJenisKonfigurasi extends Model
{
    protected $table = 'smki_jenis_konfigurasis';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function objeks(): HasMany
    {
        return $this->hasMany(SmkiObjek::class, 'jenis_konfigurasi_id');
    }
}
