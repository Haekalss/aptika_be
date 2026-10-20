<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MasterBidang extends Model
{
    protected $table = 'bidang';
    protected $primaryKey = 'id_bidang';

    protected $fillable = [
        'nama_bidang',
    ];
}
