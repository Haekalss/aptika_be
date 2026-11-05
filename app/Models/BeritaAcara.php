<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class BeritaAcara extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'berita_acara';
    protected $primaryKey = 'id_ba';
    public $incrementing = true;
    protected $keyType = 'int';

    protected $fillable = [
        'nomor_dokumen',
        'tanggal_pelaksanaan',
        'alasan_penghancuran',
        'id_pelaksana',
        'id_diketahui',
        'nama_pelaksana',
        'nip_pelaksana',
        'nama_diketahui',
        'nip_diketahui',
        'bidang_id',
    ];

    protected $casts = [
        'tanggal_pelaksanaan' => 'date:Y-m-d',
        'created_at'          => 'datetime',
        'updated_at'          => 'datetime',
        'deleted_at'          => 'datetime',
    ];

    protected $appends = [
        'nama_pelaksana_display',
        'nip_pelaksana_display',
        'nama_diketahui_display',
        'nip_diketahui_display',
        'total_media_items',
    ];

    /**
     * Relasi ke seluruh rincian media yang dihancurkan (One-to-Many).
     */
    public function detailMedia(): HasMany
    {
        return $this->hasMany(DetailMedia::class, 'id_pelaksanaan', 'id_ba')
            ->orderBy('no_urut', 'asc')
            ->orderBy('id_detail', 'asc');
    }

    /**
     * Relasi ke User sebagai Pelaksana.
     */
    public function pelaksana(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id_pelaksana', 'id');
    }

    /**
     * Relasi ke User sebagai Pihak yang Mengetahui / Approver.
     */
    public function diketahui(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id_diketahui', 'id');
    }

    /**
     * Relasi ke Unit Kerja / Bidang.
     */
    public function bidang(): BelongsTo
    {
        return $this->belongsTo(Bidang::class, 'bidang_id', 'id');
    }

    /**
     * Nama pelaksana tampilan (mengutamakan input khusus atau relasi user).
     */
    public function getNamaPelaksanaDisplayAttribute(): string
    {
        if (!empty($this->nama_pelaksana)) {
            return $this->nama_pelaksana;
        }
        return $this->pelaksana?->name ?? 'Belum Ditentukan';
    }

    public function getNipPelaksanaDisplayAttribute(): ?string
    {
        return $this->nip_pelaksana ?? null;
    }

    /**
     * Nama pejabat/pengawas yang mengetahui.
     */
    public function getNamaDiketahuiDisplayAttribute(): string
    {
        if (!empty($this->nama_diketahui)) {
            return $this->nama_diketahui;
        }
        return $this->diketahui?->name ?? 'Belum Ditentukan';
    }

    public function getNipDiketahuiDisplayAttribute(): ?string
    {
        return $this->nip_diketahui ?? null;
    }

    /**
     * Total unit/jumlah media yang dimusnahkan.
     */
    public function getTotalMediaItemsAttribute(): int
    {
        if ($this->relationLoaded('detailMedia')) {
            return (int) $this->detailMedia->sum('jumlah');
        }
        return (int) $this->detailMedia()->sum('jumlah');
    }
}
