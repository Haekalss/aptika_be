<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SmkiMatrixRole extends Model
{
    protected $table = 'smki_matrix_roles';

    protected $fillable = ['document_id', 'unit_label', 'peran', 'levels'];

    protected $casts = ['levels' => 'array'];

    public function document()
    {
        return $this->belongsTo(SmkiMatrixCompetency::class, 'document_id');
    }
}
