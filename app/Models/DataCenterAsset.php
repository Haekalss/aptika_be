<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class DataCenterAsset extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'data_center_assets';

    protected $fillable = [
        'kode_aset',
        'nama_aset',
        'kategori',
        'lokasi_rak',
        'status_operasional',
        'penanggung_jawab_default',
        'penanggung_jawab_id',
    ];

    public function penanggungJawab()
    {
        return $this->belongsTo(User::class, 'penanggung_jawab_id');
    }

    public function laporanKerusakan()
    {
        return $this->hasMany(LaporanKerusakanDataCenter::class, 'aset_id');
    }
}
