<?php

namespace App\Models;

use App\Traits\BelongsToBidang;
use Illuminate\Database\Eloquent\Model;

class SmkiKomunikasiHeader extends Model
{
    use BelongsToBidang;

    protected $table = 'smki_komunikasi_headers';

    protected $fillable = [
        'bidang_id',
        'periode',
        'no_dokumen',
        'no_revisi',
        'tanggal_berlaku',
        'disusun_nama',
        'disusun_nip',
        'mengetahui_nama',
        'mengetahui_nip',
        'kota',
        'tanggal_ttd',
    ];

    protected $casts = [
        'tanggal_ttd' => 'date',
    ];
}
