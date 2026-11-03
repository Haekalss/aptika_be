<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SmkiLaporanAuditTahunanKlausul extends Model
{
    protected $table = 'smki_laporan_audit_tahunan_klausuls';

    protected $fillable = ['objek_id', 'klausul_annex', 'uraian_klausul'];
}

