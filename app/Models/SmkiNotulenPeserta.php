<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmkiNotulenPeserta extends Model
{
    use HasFactory;

    protected $table = 'smki_notulen_pesertas';

    protected $fillable = [
        'smki_notulen_id',
        'pegawai_id',
        'nama',
        'jabatan',
        'urutan',
    ];

    public function notulen(): BelongsTo
    {
        return $this->belongsTo(SmkiNotulen::class, 'smki_notulen_id');
    }

    public function pegawai(): BelongsTo
    {
        return $this->belongsTo(Pegawai::class, 'pegawai_id');
    }
}
