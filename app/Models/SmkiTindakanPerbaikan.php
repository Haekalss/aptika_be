<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SmkiTindakanPerbaikan extends Model
{
    use SoftDeletes;

    public const SOURCES = [
        'audit_internal' => 'Audit Internal',
        'laporan_insiden' => 'Laporan Insiden',
        'audit_eksternal' => 'Audit Eksternal',
        'usulan_saran' => 'Usulan / Saran',
        'pengukuran_pemantauan' => 'Pengukuran dan pemantauan',
        'lainnya' => 'Lain-lain',
    ];

    protected $fillable = [
        'nomor_laporan', 'id_unit_kerja', 'area', 'tanggal_laporan',
        'diajukan_kepada_id', 'diajukan_kepada_nama', 'dilaporkan_oleh_id', 'dilaporkan_oleh_nama',
        'sumber_codes', 'sumber_lainnya', 'status_temuan', 'deskripsi', 'akar_masalah',
        'status', 'verifikasi', 'tanggal_verifikasi', 'catatan_verifikasi',
        'id_auditor', 'auditor_nama', 'atasan_id', 'atasan_nama',
        'created_by', 'bidang_id', 'verified_by', 'version', 'template_version',
    ];

    protected $casts = [
        'sumber_codes' => 'array',
        'tanggal_laporan' => 'date:Y-m-d',
        'tanggal_verifikasi' => 'date:Y-m-d',
        'version' => 'integer',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(SmkiTindakanPerbaikanItem::class, 'tindakan_perbaikan_id')
            ->orderBy('jenis')->orderBy('urutan')->orderBy('id');
    }

    public function verifications(): HasMany
    {
        return $this->hasMany(SmkiTindakanPerbaikanVerification::class, 'tindakan_perbaikan_id')->latest('id');
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $query->where('bidang_id', $user->bidang_id);

        return $user->role === 'admin' ? $query : $query->where('created_by', $user->id);
    }
}
