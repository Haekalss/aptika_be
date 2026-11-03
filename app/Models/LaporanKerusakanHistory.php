<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LaporanKerusakanHistory extends Model
{
    use HasFactory;

    protected $table = 'laporan_kerusakan_histories';

    protected $fillable = [
        'laporan_id',
        'user_id',
        'action',
        'status_from',
        'status_to',
        'catatan',
    ];

    public function laporan()
    {
        return $this->belongsTo(LaporanKerusakanDataCenter::class, 'laporan_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
