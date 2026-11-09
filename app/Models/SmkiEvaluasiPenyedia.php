<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SmkiEvaluasiPenyedia extends Model
{
    use SoftDeletes;

    public const STATUSES = ['draft', 'diajukan', 'revisi', 'selesai'];

    public const SIFAT = [
        'pengadaan_baru' => 'Pengadaan Baru',
        'maintenance_perawatan' => 'Maintenance / Perawatan',
        'lainnya' => 'Lainnya',
    ];

    public const TEMPLATE = [
        'nomor_dokumen' => 'FR-021/KOM.03.05/SANDIKAMI',
        'nomor_revisi' => '1.1',
        'tanggal_berlaku' => '07 Juli 2022',
    ];

    protected $table = 'smki_evaluasi_penyedias';

    protected $fillable = [
        'created_by',
        'penyedia_id',
        'admin_id',
        'rekomendasi_id',
        'no_kontrak',
        'nomor_dokumen',
        'nomor_revisi',
        'tanggal_berlaku',
        'sifat_pekerjaan',
        'sifat_pekerjaan_lainnya',
        'total_nilai',
        'status',
        'catatan_revisi',
        'siklus',
        'tempat',
        'tanggal_evaluasi',
        'ttd_evaluator_nama',
        'ttd_evaluator_nip',
        'ttd_evaluator_jabatan',
        'tanggal_ttd_evaluator',
        'ttd_admin_nama',
        'ttd_admin_nip',
        'ttd_admin_jabatan',
        'tanggal_ttd_admin',
        'version',
    ];

    protected $casts = [
        'tanggal_evaluasi' => 'date:Y-m-d',
        'tanggal_ttd_evaluator' => 'datetime',
        'tanggal_ttd_admin' => 'datetime',
        'total_nilai' => 'integer',
        'siklus' => 'integer',
        'version' => 'integer',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function penyedia(): BelongsTo
    {
        return $this->belongsTo(SmkiPenyediaJasa::class, 'penyedia_id');
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    public function rekomendasi(): BelongsTo
    {
        return $this->belongsTo(SmkiRekomendasiEvaluasi::class, 'rekomendasi_id');
    }

    public function penilaian(): HasMany
    {
        return $this->hasMany(SmkiEvaluasiPenyediaPenilaian::class, 'evaluasi_id')
            ->orderBy('kriteria_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(SmkiEvaluasiPenyediaLog::class, 'evaluasi_id')
            ->orderBy('created_at')->orderBy('id');
    }

    /**
     * Admin dapat melihat seluruh evaluasi; pengguna lain hanya evaluasi
     * yang dibuatnya. Evaluator adalah perwakilan vendor (tanpa akun)
     * sehingga tidak ada hak akses berbasis evaluator.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->role === 'admin') {
            return $query;
        }

        return $query->where('created_by', $user->id);
    }
}
