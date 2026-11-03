<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ItemScreening extends Model
{
    protected $table = 'item_screening';
    protected $primaryKey = 'id_item';

    protected $fillable = [
        'nama_item',
    ];
}
