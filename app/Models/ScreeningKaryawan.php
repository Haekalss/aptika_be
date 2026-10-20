<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ScreeningKaryawan extends Model
{
    use SoftDeletes;

    protected $table = 'screening_karyawan';
    protected $primaryKey = 'id_screening';

    protected $fillable = [
        'nama_karyawan',
        'id_jabatan',
        'id_bidang',
        'tahun',
        'id_detail',
        'tanggal_screening',
        'kesimpulan',
        'catatan',
    ];

    public function jabatan(): BelongsTo
    {
        return $this->belongsTo(Jabatan::class, 'id_jabatan', 'id_jabatan');
    }

    public function bidang(): BelongsTo
    {
        return $this->belongsTo(MasterBidang::class, 'id_bidang', 'id_bidang');
    }

    public function details(): HasMany
    {
        return $this->hasMany(DetailScreening::class, 'id_screening', 'id_screening');
    }
}
