<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class SmkiFormulirAsetInformasi extends Model
{
    use SoftDeletes;

    protected $table = 'smki_formulir_aset_informasis';

    protected $fillable = [
        'no_formulir',
        'revisi',
        'tanggal',
        'bidang_nama',
        'klasifikasi_dokumen',
        'bidang_id',
        'user_id',
        'catatan',
    ];

    protected $casts = [
        'tanggal' => 'date:Y-m-d',
    ];

    /**
     * Relasi ke pembuat dokumen (User)
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Relasi ke Detail Tab 1: Aset Informasi Unit Kerja
     */
    public function unitKerjaDetails(): HasMany
    {
        return $this->hasMany(SmkiAsetInformasiUnitKerja::class, 'formulir_id')->orderBy('no_urut', 'asc');
    }

    /**
     * Relasi ke Detail Tab 2: Aset Intangible
     */
    public function intangibleDetails(): HasMany
    {
        return $this->hasMany(SmkiAsetInformasiIntangible::class, 'formulir_id')->orderBy('no_urut', 'asc');
    }
}
