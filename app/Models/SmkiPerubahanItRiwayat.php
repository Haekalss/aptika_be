<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SmkiPerubahanItRiwayat extends Model
{
    protected $table = 'smki_perubahan_it_riwayats';

    protected $fillable = [
        'perubahan_it_id',
        'aksi',
        'status_dari',
        'status_ke',
        'catatan',
        'user_id',
        'nama_user',
    ];

    public function perubahanIt()
    {
        return $this->belongsTo(SmkiPerubahanIt::class, 'perubahan_it_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
