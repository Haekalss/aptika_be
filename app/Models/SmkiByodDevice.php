<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmkiByodDevice extends Model
{
    protected $table = 'smki_byod_devices';

    protected $fillable = [
        'byod_form_id',
        'nama',
        'nip',
        'jenis_perangkat',
        'merek_tipe',
        'nomor_seri',
    ];

    public function form(): BelongsTo
    {
        return $this->belongsTo(SmkiByodForm::class, 'byod_form_id');
    }
}
