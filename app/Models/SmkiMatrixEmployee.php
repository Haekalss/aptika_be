<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SmkiMatrixEmployee extends Model
{
    protected $table = 'smki_matrix_employees';

    protected $fillable = [
        'document_id', 'aset_sdm_id', 'nama', 'nip', 'jabatan', 'peran', 'levels', 'usulan_training',
    ];

    protected $casts = ['levels' => 'array'];

    public function document()
    {
        return $this->belongsTo(SmkiMatrixCompetency::class, 'document_id');
    }
}
