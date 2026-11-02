<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SmkiRuanganArea extends Model
{
    use HasFactory;

    protected $table = 'smki_ruangan_areas';

    protected $fillable = [
        'kode_ruangan',
        'nama_ruangan',
        'deskripsi_area',
    ];
}
