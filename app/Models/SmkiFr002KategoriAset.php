<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SmkiFr002KategoriAset extends Model
{
    use HasFactory;

    protected $table = 'smki_fr002_kategori_asets';
    protected $primaryKey = 'kode_kategori';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'kode_kategori',
        'nama_kategori_aset',
        'deskripsi',
        'urutan',
    ];

    public function risikos()
    {
        return $this->hasMany(SmkiFr002Risiko::class, 'kode_kategori', 'kode_kategori');
    }
}
