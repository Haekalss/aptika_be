<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SmkiFr002KontrolAnnexIso extends Model
{
    use HasFactory;

    protected $table = 'smki_fr002_kontrol_annex_isos';

    protected $fillable = [
        'kode_annex',
        'nama_kontrol_iso',
        'kategori_tema',
    ];
}
