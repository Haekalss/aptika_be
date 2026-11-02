<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class SmkiHakAksesFisik extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'smki_hak_akses_fisiks';

    protected $fillable = [
        'nomor_dokumen',
        'tanggal_pengajuan',
        'pegawai_id',
        'nama_pemohon',
        'nip',
        'jabatan',
        'unit_kerja',
        'no_handphone',
        'email',
        'jenis_permintaan',
        'sifat_akses',
        'waktu_akses',
        'waktu_akses_lainnya',
        'berlaku_sampai',
        'ruangan_akses',
        'keperluan_alasan',
        'keperluan_lainnya',
        'status_pengajuan',
        'catatan',
        'pemohon_nama',
        'pemohon_nip',
        'atasan_nama',
        'atasan_nip',
        'penanggung_jawab_nama',
        'penanggung_jawab_nip',
        'agen_nama',
        'agen_nip',
        'header_no_dokumen',
        'header_no_revisi',
        'header_tanggal_berlaku',
        'is_deleted',
        'bidang_id',
        'user_id',
    ];

    protected $casts = [
        'tanggal_pengajuan' => 'date',
        'berlaku_sampai' => 'date',
        'header_tanggal_berlaku' => 'date',
        'ruangan_akses' => 'array',
        'keperluan_alasan' => 'array',
        'is_deleted' => 'boolean',
    ];

    public function pegawai(): BelongsTo
    {
        return $this->belongsTo(Pegawai::class, 'pegawai_id');
    }

    public function bidang(): BelongsTo
    {
        return $this->belongsTo(Bidang::class, 'bidang_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Ruangan yang terpilih dalam bentuk string yang rapi.
     */
    public function getRuanganStringAttribute(): string
    {
        if (empty($this->ruangan_akses)) {
            return '-';
        }
        return is_array($this->ruangan_akses) ? implode(', ', $this->ruangan_akses) : (string) $this->ruangan_akses;
    }

    /**
     * Format Ringkas Jenis & Sifat Akses.
     */
    public function getJenisSifatStringAttribute(): string
    {
        $jenis = $this->jenis_permintaan ?: 'Pendaftaran';
        $sifat = $this->sifat_akses ?: 'Permanen';
        return "{$sifat} / {$jenis}";
    }
}
