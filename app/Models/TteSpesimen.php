<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TteSpesimen extends Model
{
    protected $table = 'tte_spesimens';

    protected $fillable = [
        'pegawai_id',
        'nama',
        'nip',
        'jabatan',
        'pangkat',
        'golongan',
        'unit',
        'kalimat',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /** Ambil spesimen aktif pertama (dipakai sebagai default cetak). */
    public static function default(): ?self
    {
        return static::where('is_active', true)->orderBy('id')->first();
    }
}
