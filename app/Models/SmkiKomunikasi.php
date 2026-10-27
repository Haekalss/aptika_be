<?php

namespace App\Models;

use App\Traits\BelongsToBidang;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SmkiKomunikasi extends Model
{
    use BelongsToBidang, SoftDeletes;

    protected $table = 'smki_komunikasis';

    protected $fillable = [
        'bidang_id',
        'user_id',
        'tipe',
        'judul',
        'klasifikasi',
        'pengirim',
        'penerima',
        'frekuensi',
        'metode',
        'periode',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
