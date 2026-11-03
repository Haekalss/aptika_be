<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SmkiSpesimenTte extends Model
{
    protected $table = 'smki_spesimen_ttes';

    protected $fillable = ['user_id', 'nama_asli', 'path', 'mime', 'ukuran'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}