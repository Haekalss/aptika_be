<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SmkiPenyediaJasa extends Model
{
    protected $table = 'smki_penyedia_jasas';

    protected $fillable = ['nama_penyedia', 'contact_person', 'email'];
}
