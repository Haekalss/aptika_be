<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SmkiPerubahanIt extends Model
{
    public const STATUS_DRAFT        = 'draft';
    public const STATUS_DIAJUKAN     = 'diajukan';
    public const STATUS_DIKEMBALIKAN = 'dikembalikan';
    public const STATUS_DITERIMA     = 'diterima';
    public const STATUS_DITOLAK      = 'ditolak';
    public const STATUS_DISETUJUI    = 'disetujui';
    public const STATUS_DILAKSANAKAN = 'dilaksanakan';

    public const STATUS_LABEL = [
        self::STATUS_DRAFT        => 'Draft',
        self::STATUS_DIAJUKAN     => 'Diajukan',
        self::STATUS_DIKEMBALIKAN => 'Dikembalikan',
        self::STATUS_DITERIMA     => 'Diterima',
        self::STATUS_DITOLAK      => 'Ditolak',
        self::STATUS_DISETUJUI    => 'Disetujui',
        self::STATUS_DILAKSANAKAN => 'Dilaksanakan',
    ];

    public const JENIS_PERUBAHAN = [
        'permintaan_baru'    => 'Permintaan Baru',
        'peningkatan_sistem' => 'Peningkatan Sistem',
        'perbaikan_sistem'   => 'Perbaikan Sistem',
    ];

    public const TINGKAT_RISIKO = [
        'malapetaka'   => 'Malapetaka',
        'sangat_berat' => 'Sangat Berat',
        'berat'        => 'Berat',
        'agak_berat'   => 'Agak Berat',
        'tidak_berat'  => 'Tidak Berat',
    ];

    /** Kolom isian formulir yang boleh diisi/diubah oleh Pemohon. */
    public const FORM_FIELDS = [
        'judul',
        'unit_kerja_id',
        'perangkat_daerah',
        'nomor_surat_kontak',
        'jenis_perubahan',
        'perubahan_diharapkan',
        'latar_belakang',
        'rincian_perubahan',
        'tingkat_risiko',
        'keterangan_risiko',
        'solusi_diharapkan',
        'risiko_perubahan',
        'alternatif_perubahan',
        'estimasi_biaya',
        'tanggal_perubahan',
        'jam_mulai',
        'jam_selesai',
    ];

    /** Kolom nama pada tabel master_unit_kerja. */
    public const UNIT_NAME_COLUMN = 'nama_unit';

    protected $table = 'smki_perubahan_its';

    protected $fillable = [
        'no_rfc',
        'tanggal',
        'status',
        'pemohon',
        ...self::FORM_FIELDS,
        'tanggal_diajukan',
        'catatan_pengembalian',
        'diterima_oleh_id',
        'diterima_oleh_nama',
        'diterima_pada',
        'disetujui_oleh_id',
        'disetujui_oleh_nama',
        'disetujui_pada',
        'alasan_penolakan',
        'dilaksanakan_oleh_id',
        'dilaksanakan_oleh_nama',
        'dilaksanakan_pada',
        'tanggal_pelaksanaan',
        'catatan_pelaksanaan',
        'created_by',
    ];

    protected $casts = [
        'tanggal'             => 'date',
        'tanggal_perubahan'   => 'date',
        'estimasi_biaya'      => 'decimal:2',
        'tanggal_diajukan'    => 'datetime',
        'diterima_pada'       => 'datetime',
        'disetujui_pada'      => 'datetime',
        'dilaksanakan_pada'   => 'datetime',
        'tanggal_pelaksanaan' => 'date',
    ];

    public function lampirans()
    {
        return $this->hasMany(SmkiPerubahanItLampiran::class, 'perubahan_it_id');
    }

    public function riwayats()
    {
        return $this->hasMany(SmkiPerubahanItRiwayat::class, 'perubahan_it_id')->orderBy('id');
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
