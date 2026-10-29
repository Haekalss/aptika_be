<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SmkiLaporanAuditTahunan extends Model
{
    use SoftDeletes;

    protected $table = 'smki_laporan_audit_tahunans';

    protected $fillable = [
        'tahun_smki', 'no_dokumen', 'no_revisi', 'tanggal_berlaku',
        'kriteria_audit', 'tujuan', 'disusun_oleh', 'jabatan_penyusun',
        'disahkan_oleh', 'jabatan_pengesah', 'status', 'created_by',
    ];

    protected $casts = [
        'tanggal_berlaku' => 'date:Y-m-d',
    ];

    public function timAuditor()
    {
        return $this->hasMany(SmkiLaporanAuditTahunanTim::class, 'laporan_id')->orderBy('id');
    }

    public function objekAudit()
    {
        return $this->hasMany(SmkiLaporanAuditTahunanObjek::class, 'laporan_id')->orderBy('id');
    }
}

