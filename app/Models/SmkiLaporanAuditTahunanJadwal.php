<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SmkiLaporanAuditTahunanJadwal extends Model
{
    protected $table = 'smki_laporan_audit_tahunan_jadwals';

    protected $fillable = ['objek_id', 'bulan'];
}

