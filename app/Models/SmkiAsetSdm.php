<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SmkiAsetSdm extends Model
{
    /**
     * Nama kolom yang berisi nama unit kerja di tabel master_unit_kerja.
     * SESUAIKAN jika nama kolom di tabel Anda berbeda.
     */
    public const UNIT_NAME_COLUMN = 'nama_unit';

    protected $table = 'smki_aset_sdms';

    protected $fillable = [
        'nama',
        'nip',
        'pangkat_golongan',
        'jabatan',
        'tmt_jabatan',
        'unit_kerja_id',
        'jabatan_smki',
        'tanggung_jawab',
        'jenjang',
        'jurusan',
        'tahun_lulus',
        'created_by',
    ];

    protected $casts = [
        'tmt_jabatan' => 'date',
        'tahun_lulus' => 'integer',
    ];

    /**
     * Kode aset diturunkan dari id: 1 => SDM-001.
     */
    public function getKodeAsetAttribute(): string
    {
        return 'SDM-' . str_pad((string) $this->id, 3, '0', STR_PAD_LEFT);
    }

    public function pelatihans()
    {
        return $this->hasMany(SmkiAsetSdmPelatihan::class, 'aset_sdm_id');
    }

    public function unitKerja()
    {
        return $this->belongsTo(MasterUnitKerja::class, 'unit_kerja_id', 'id_unit_kerja');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}