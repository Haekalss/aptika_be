<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SmkiRekomendasiEvaluasi extends Model
{
    protected $table = 'smki_rekomendasi_evaluasis';

    protected $fillable = ['kategori', 'nilai_min', 'nilai_max', 'keterangan'];

    protected $casts = [
        'nilai_min' => 'integer',
        'nilai_max' => 'integer',
    ];

    public static function forTotal(int $total): ?self
    {
        return static::query()
            ->where('nilai_min', '<=', $total)
            ->where('nilai_max', '>=', $total)
            ->first();
    }
}
