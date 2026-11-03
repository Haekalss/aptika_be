<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SmkiLaporanAuditTahunanObjek extends Model
{
    protected $table = 'smki_laporan_audit_tahunan_objeks';

    protected $fillable = ['laporan_id', 'kode_objek', 'objek_audit', 'ruang_lingkup', 'unit_kerja'];

    public function klausul()
    {
        return $this->hasMany(SmkiLaporanAuditTahunanKlausul::class, 'objek_id')->orderBy('id');
    }

    public function jadwal()
    {
        return $this->hasMany(SmkiLaporanAuditTahunanJadwal::class, 'objek_id')->orderBy('bulan');
    }
}

