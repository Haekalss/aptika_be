<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SmkiLaporanAuditTahunanTim extends Model
{
    protected $table = 'smki_laporan_audit_tahunan_tims';

    protected $fillable = ['laporan_id', 'nama_auditor', 'unit_kerja', 'peran'];
}

