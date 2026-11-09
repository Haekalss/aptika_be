<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SmkiFr002Risiko extends Model
{
    use HasFactory;

    protected $table = 'smki_fr002_risikos';

    protected $fillable = [
        'header_id',
        'kode_kategori',
        'kode_annex',
        'nama_aset_proses',
        'kerawanan_vulnerability',
        'ancaman_threat',
        'kategori_cia',
        'uraian_dampak',
        'inherent_likelihood',
        'inherent_impact',
        'inherent_score',
        'inherent_kategori',
        'existing_control',
        'referensi_dokumen',
        'kontrol_annex_iso',
        'current_likelihood',
        'current_impact',
        'current_score',
        'current_kategori',
        'status_risiko_current',
        'residual_likelihood',
        'residual_impact',
        'residual_score',
        'residual_kategori',
        'status_risiko_residual',
        'is_deleted',
        'created_by',
    ];

    protected $casts = [
        'inherent_likelihood' => 'integer',
        'inherent_impact' => 'integer',
        'inherent_score' => 'integer',
        'current_likelihood' => 'integer',
        'current_impact' => 'integer',
        'current_score' => 'integer',
        'residual_likelihood' => 'integer',
        'residual_impact' => 'integer',
        'residual_score' => 'integer',
        'is_deleted' => 'boolean',
    ];

    public function header()
    {
        return $this->belongsTo(SmkiFr002Header::class, 'header_id');
    }

    public function kategoriAset()
    {
        return $this->belongsTo(SmkiFr002KategoriAset::class, 'kode_kategori', 'kode_kategori');
    }

    public function treatmentPlan()
    {
        return $this->hasOne(SmkiFr002RiskTreatmentPlan::class, 'risiko_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Hitung Skor Matriks $L \times I$ dan kategorinya.
     * Mengikuti formula template resmi FR-002 (FORMAT RISK REGISTER 2023):
     * 1-4: Rendah, 5-11: Menengah, 12-15: Tinggi, 16-20: Sangat Tinggi, 25: Ekstrim.
     * Status: skor >= 5 => Kontrol/Transfer/Void, skor 1-4 => Dapat Diterima.
     */
    public static function kalkulasiMatriks($likelihood, $impact)
    {
        $l = max(1, min(5, (int)$likelihood));
        $i = max(1, min(5, (int)$impact));
        $score = $l * $i;

        if ($score >= 25) {
            $kategori = 'Ekstrim';
        } elseif ($score >= 16) {
            $kategori = 'Sangat Tinggi';
        } elseif ($score >= 12) {
            $kategori = 'Tinggi';
        } elseif ($score >= 5) {
            $kategori = 'Menengah';
        } else {
            $kategori = 'Rendah';
        }

        $status = $score >= 5 ? 'Kontrol/Transfer/Void' : 'Dapat Diterima';

        return [
            'likelihood' => $l,
            'impact' => $i,
            'score' => $score,
            'kategori' => $kategori,
            'status' => $status,
        ];
    }
}
