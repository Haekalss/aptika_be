<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SmkiMatrixTraining extends Model
{
    protected $table = 'smki_matrix_trainings';

    protected $fillable = ['document_id', 'nama', 'jabatan', 'usulan_training'];

    public function document()
    {
        return $this->belongsTo(SmkiMatrixCompetency::class, 'document_id');
    }
}
