<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HakAksesTi extends Model
{
    protected $table = 'hak_akses_ti';

    protected $primaryKey = 'id_hak_akses';

    protected $fillable = [
        'nomor_request',
        'nama_pemohon',
        'nip_id_pegawai',
        'jabatan',
        'email',
        'kontak_person',
        'id_unit_kerja',
        'id_jenis_permohonan',
        'id_sistem_aplikasi',
        'id_level_akses',
        'sifat_akses',
        'waktu_akses',
        'waktu_akses_lainnya',
        'masa_berlaku_mulai',
        'masa_berlaku_selesai',
        'keperluan',
        'sistem_lainnya',
        'modul_fitur',
        'persetujuan_ketentuan',
        'status_permohonan',
        'atasan_nama',
        'atasan_nip',
        'penanggung_jawab_nama',
        'penanggung_jawab_nip',
        'agen_nama',
        'agen_nip',
        'bidang_id',
        'user_id',
    ];

    protected $casts = [
        'modul_fitur' => 'array',
        'persetujuan_ketentuan' => 'boolean',
        'masa_berlaku_mulai' => 'date',
        'masa_berlaku_selesai' => 'date',
    ];

    public function unitKerja()
    {
        return $this->belongsTo(MasterUnitKerja::class, 'id_unit_kerja', 'id_unit_kerja');
    }

    public function jenisPermohonan()
    {
        return $this->belongsTo(MasterJenisPermohonan::class, 'id_jenis_permohonan', 'id_jenis_permohonan');
    }

    public function sistemAplikasi()
    {
        return $this->belongsTo(MasterSistemAplikasi::class, 'id_sistem_aplikasi', 'id_sistem_aplikasi');
    }

    public function levelAkses()
    {
        return $this->belongsTo(MasterLevelAkses::class, 'id_level_akses', 'id_level_akses');
    }

    public function jenisAkses()
    {
        return $this->belongsToMany(
            MasterJenisAkses::class,
            'hak_akses_jenis',
            'id_hak_akses',
            'id_jenis_akses',
            'id_hak_akses',
            'id_jenis_akses'
        );
    }

    public function bidang()
    {
        return $this->belongsTo(Bidang::class, 'bidang_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    protected $appends = [
        'nama_unit_kerja',
        'nama_jenis_permohonan',
        'nama_sistem',
        'nama_level_akses',
        'daftar_jenis_akses',
    ];

    public function getNamaUnitKerjaAttribute()
    {
        return $this->unitKerja?->nama_unit;
    }

    public function getNamaJenisPermohonanAttribute()
    {
        return $this->jenisPermohonan?->nama_jenis;
    }

    public function getNamaSistemAttribute()
    {
        return $this->sistemAplikasi?->nama_sistem;
    }

    public function getNamaLevelAksesAttribute()
    {
        return $this->levelAkses?->nama_level;
    }

    public function getDaftarJenisAksesAttribute()
    {
        return $this->jenisAkses->pluck('nama_akses')->values();
    }

    public static function generateNomorRequest(): string
    {
        $year = date('Y');
        $count = self::whereYear('created_at', $year)->count() + 1;

        return sprintf('HAK-AKSES-%s-%04d', $year, $count);
    }
}
