<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SmkiMatrixCompetency extends Model
{
    protected $table = 'smki_matrix_competencies';

    protected $fillable = [
        'user_id',
        'bidang_id',
        'nomor_dokumen',
        'no_revisi',
        'tanggal_berlaku',
        'nama_unit_kerja',
        'competencies',
    ];

    protected $casts = [
        'tanggal_berlaku' => 'date',
        'competencies' => 'array',
    ];

    public function roles()
    {
        return $this->hasMany(SmkiMatrixRole::class, 'document_id');
    }

    public function employees()
    {
        return $this->hasMany(SmkiMatrixEmployee::class, 'document_id');
    }

    public function trainings()
    {
        return $this->hasMany(SmkiMatrixTraining::class, 'document_id');
    }
}
