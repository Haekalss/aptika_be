<?php

namespace App\Models;

use App\Traits\BelongsToBidang;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SmkiByodForm extends Model
{
    use BelongsToBidang, SoftDeletes;

    protected $table = 'smki_byod_forms';

    protected $fillable = [
        'bidang_id',
        'user_id',
        'nama',
        'nip',
        'jabatan',
        'unit_kerja',
        'ditandatangani_di',
        'tanggal_ttd',
    ];

    protected $casts = [
        'tanggal_ttd' => 'date:Y-m-d',
    ];

    public function perangkat(): HasMany
    {
        return $this->hasMany(SmkiByodDevice::class, 'byod_form_id')->orderBy('id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
