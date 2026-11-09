<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class SmkiEvaluasiKriteria extends Model
{
    protected $table = 'smki_evaluasi_kriterias';

    protected $fillable = ['urutan', 'nama_kriteria'];

    protected $casts = ['urutan' => 'integer'];

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('urutan')->orderBy('id');
    }
}
