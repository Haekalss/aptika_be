<?php

namespace App\Models;

use App\Traits\BelongsToBidang;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SmkiPemeriksaanCctv extends Model
{
    use BelongsToBidang, SoftDeletes;

    protected $table = 'smki_pemeriksaan_cctvs';

    protected $fillable = [
        'bidang_id',
        'user_id',
        'lingkup',
        'perangkat_perekaman',
        'tanggal_pemeriksaan',
        'kesesuaian_waktu',
        'nama_pemeriksa',
    ];

    protected $casts = [
        'tanggal_pemeriksaan' => 'date:Y-m-d',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(SmkiPemeriksaanCctvItem::class, 'pemeriksaan_cctv_id')->orderBy('id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
