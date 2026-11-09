<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SmkiFr002Header extends Model
{
    use HasFactory;

    protected $table = 'smki_fr002_headers';

    protected $fillable = [
        'tahun_periode',
        'ruang_lingkup',
        'no_revisi',
        'tanggal_dokumen',
        'klasifikasi',
        'jabatan_pengesah',
        'nama_pengesah',
        'nip_pengesah',
    ];

    protected $casts = [
        'tanggal_dokumen' => 'date',
    ];

    public function risikos()
    {
        return $this->hasMany(SmkiFr002Risiko::class, 'header_id');
    }
}
