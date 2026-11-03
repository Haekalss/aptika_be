<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmkiDaftarAsetVirtualInstance extends Model
{
    protected $table = 'smki_daftar_aset_virtual_instances';

    protected $guarded = ['id'];

    public function daftarAsetVirtual(): BelongsTo
    {
        return $this->belongsTo(SmkiDaftarAsetVirtual::class, 'daftar_aset_virtual_id');
    }
}
