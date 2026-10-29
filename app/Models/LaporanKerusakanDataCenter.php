<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class LaporanKerusakanDataCenter extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'laporan_kerusakan_data_centers';

    protected $fillable = [
        'nomor_laporan',
        'no_dokumen',
        'no_revisi',
        'tanggal_berlaku',
        'tanggal',
        'jam',
        'aset_id',
        'nama_aset',
        'kode_aset',
        'lokasi_rak',
        'jenis_kerusakan',
        'penyebab',
        'uraian_tindakan',
        'target_selesai',
        'tanggal_selesai_aktual',
        'pelapor_id',
        'pelapor_nama',
        'penanggung_jawab_id',
        'penanggung_jawab_nama',
        'supervisor_id',
        'supervisor_nama',
        'status',
        'catatan_validasi',
        'validated_at',
    ];

    protected $casts = [
        'tanggal' => 'date:Y-m-d',
        'tanggal_berlaku' => 'date:Y-m-d',
        'target_selesai' => 'date:Y-m-d',
        'tanggal_selesai_aktual' => 'datetime',
        'validated_at' => 'datetime',
    ];

    public function asset()
    {
        return $this->belongsTo(DataCenterAsset::class, 'aset_id');
    }

    public function pelapor()
    {
        return $this->belongsTo(User::class, 'pelapor_id');
    }

    public function penanggungJawab()
    {
        return $this->belongsTo(User::class, 'penanggung_jawab_id');
    }

    public function supervisor()
    {
        return $this->belongsTo(User::class, 'supervisor_id');
    }

    public function histories()
    {
        return $this->hasMany(LaporanKerusakanHistory::class, 'laporan_id')->orderBy('created_at', 'desc');
    }
}
