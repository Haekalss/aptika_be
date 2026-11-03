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
    ];
}
