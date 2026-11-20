<?php

namespace App\Models;

use App\Traits\BelongsToBidang;
use Illuminate\Database\Eloquent\Model;

class SmkiDaftarRole extends Model
{
    use BelongsToBidang;

    protected $table = 'smki_daftar_role';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'bidang_id' => 'integer',
            'recorder_id' => 'integer',
            'payload' => 'array',
        ];
    }
}
