<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class DataCenterAsset extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'data_center_assets';

    protected $fillable = [
        'kode_aset',
        'nama_aset',
        'kategori',
        'lokasi_rak',
        'status_operasional',
        'penanggung_jawab_default',
        'penanggung_jawab_id',
    ];

    public function penanggungJawab()
    {
        return $this->belongsTo(User::class, 'penanggung_jawab_id');
    }

    public function laporanKerusakan()
    {
        return $this->hasMany(LaporanKerusakanDataCenter::class, 'aset_id');
    }

    /**
     * Relasi ke Daftar Aset TI jika kode cocok
     */
    public function daftarAsetTi()
    {
        return $this->belongsTo(DaftarAsetTi::class, 'kode_aset', 'kode');
    }

    /**
     * Sinkronisasi data dari Daftar Aset TI ke DataCenterAsset.
     * Menerapkan prinsip Single Source of Truth (ISO 27001 A.8).
     */
    public static function syncFromDaftarAsetTi(?DaftarAsetTi $tiAsset = null)
    {
        $syncItem = function ($item) {
            $kode = !empty($item->kode) ? trim($item->kode) : ('AST-' . str_pad($item->id, 5, '0', STR_PAD_LEFT));
            $kategori = $item->kategori?->nama_kategori ?? 'Aset TI';
            $lokasi = !empty($item->lokasi) ? trim($item->lokasi) : 'Data Center / Ruang Server';
            $pj = $item->penanggungJawab?->nama_pj ?? 'Tim Pengelola Aset TI';

            return self::updateOrCreate(
                ['kode_aset' => $kode],
                [
                    'nama_aset' => $item->nama_aset,
                    'kategori' => $kategori,
                    'lokasi_rak' => $lokasi,
                    'penanggung_jawab_default' => $pj,
                ]
            );
        };

        if ($tiAsset) {
            return $syncItem($tiAsset);
        }

        $allTi = DaftarAsetTi::with(['kategori', 'penanggungJawab'])->get();
        foreach ($allTi as $ti) {
            $syncItem($ti);
        }
    }

    /**
     * Pastikan master aset memiliki data default jika database masih kosong.
     */
    public static function ensureDefaultAssets()
    {
        if (self::count() > 0) {
            return;
        }

        // Jika DaftarAsetTi sudah memiliki data, prioritaskan sinkronisasi dari sana
        if (DaftarAsetTi::count() > 0) {
            self::syncFromDaftarAsetTi();
            return;
        }

        // Fallback default perangkat Data Center jika belum ada data sama sekali
        $defaults = [
            [
                'kode_aset' => 'DC-SRV-001',
                'nama_aset' => 'Server Dell PowerEdge R740 - Database Gateway Jabar',
                'kategori' => 'Server',
                'lokasi_rak' => 'Data Center Utama, Rak A-01 / U12',
                'status_operasional' => 'Operasional',
                'penanggung_jawab_default' => 'Andi Ramadhan (Teknisi Server)',
            ],
            [
                'kode_aset' => 'DC-UPS-003',
                'nama_aset' => 'UPS APC Symmetra PX 48kW Power Array',
                'kategori' => 'UPS',
                'lokasi_rak' => 'Data Center Utama, Ruang Daya P-02',
                'status_operasional' => 'Operasional',
                'penanggung_jawab_default' => 'Budi Santoso (M&E Facility)',
            ],
            [
                'kode_aset' => 'DC-SW-002',
                'nama_aset' => 'Core Switch Cisco Catalyst 9500 48-Port 10G',
                'kategori' => 'Switch',
                'lokasi_rak' => 'Data Center Utama, Rak B-02 / U24',
                'status_operasional' => 'Operasional',
                'penanggung_jawab_default' => 'Cahyo Wibowo (Network Engineer)',
            ],
            [
                'kode_aset' => 'DC-AC-001',
                'nama_aset' => 'PAC Daikin Precision Air Cooling Unit 01',
                'kategori' => 'Cooling/AC',
                'lokasi_rak' => 'Data Center Utama, Hallway Dingin Sisi Timur',
                'status_operasional' => 'Operasional',
                'penanggung_jawab_default' => 'Budi Santoso (M&E Facility)',
            ],
            [
                'kode_aset' => 'DC-STR-002',
                'nama_aset' => 'Dell EMC Unity 480F All-Flash Storage Array',
                'kategori' => 'Storage',
                'lokasi_rak' => 'Data Center Utama, Rak A-03 / U08-U14',
                'status_operasional' => 'Operasional',
                'penanggung_jawab_default' => 'Andi Ramadhan (Teknisi Server)',
            ],
            [
                'kode_aset' => 'DC-RTR-001',
                'nama_aset' => 'Router Cisco ASR 1001-X Edge Gateway Intra-Gov',
                'kategori' => 'Router',
                'lokasi_rak' => 'Data Center Utama, Rak B-01 / U30',
                'status_operasional' => 'Operasional',
                'penanggung_jawab_default' => 'Cahyo Wibowo (Network Engineer)',
            ],
        ];

        foreach ($defaults as $item) {
            self::updateOrCreate(['kode_aset' => $item['kode_aset']], $item);
        }
    }
}
