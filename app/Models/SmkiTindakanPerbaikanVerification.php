<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SmkiTindakanPerbaikanVerification extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'verifikasi', 'tanggal_verifikasi', 'catatan_verifikasi', 'auditor_nama',
        'atasan_nama', 'verified_by', 'content_version', 'created_at',
    ];

    protected $casts = ['tanggal_verifikasi' => 'date:Y-m-d', 'created_at' => 'datetime', 'content_version' => 'integer'];
}
