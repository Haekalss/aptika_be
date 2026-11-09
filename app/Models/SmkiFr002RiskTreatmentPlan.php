<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SmkiFr002RiskTreatmentPlan extends Model
{
    use HasFactory;

    protected $table = 'smki_fr002_risk_treatment_plans';

    protected $fillable = [
        'risiko_id',
        'opsi_penanggulangan',
        'uraian_rencana',
        'target_selesai',
        'pic_penanggung_jawab',
        'prioritas',
        'ownership',
        'status_monitoring',
        'keterangan',
    ];

    protected $casts = [
        'target_selesai' => 'date',
    ];

    public function risiko()
    {
        return $this->belongsTo(SmkiFr002Risiko::class, 'risiko_id');
    }
}
