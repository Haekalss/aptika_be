<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SmkiPerubahanItLampiran extends Model
{
    protected $table = 'smki_perubahan_it_lampirans';

    protected $fillable = ['perubahan_it_id', 'nama_asli', 'path', 'mime', 'ukuran'];

    protected $casts = [
        'ukuran' => 'integer',
    ];

    public function perubahanIt()
    {
        return $this->belongsTo(SmkiPerubahanIt::class, 'perubahan_it_id');
    }
}
