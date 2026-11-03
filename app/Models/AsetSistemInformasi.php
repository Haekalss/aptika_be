<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AsetSistemInformasi extends Model
{
    use HasFactory;

    protected $table = 'aset_sistem_informasi';

    protected $fillable = [
        'kode_aset_ti',
        'kode_virtualisasi',
        'kode_sistem_info',
        'nama_sistem',
        'ip_address',
        'deskripsi',
        'spesifikasi',
        'kategori_sistem',
        'pemilik',
        'klasifikasi',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
