<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SmkiSkalaNilai extends Model
{
    protected $table = 'smki_skala_nilais';

    protected $fillable = ['label', 'skor'];

    protected $casts = ['skor' => 'integer'];
}
