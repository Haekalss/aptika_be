<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class SmkiNotulen extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'smki_notulens';

    protected $fillable = [
        'nomor_notulen',
        'no_dokumen',
        'no_revisi',
        'tanggal_berlaku',
        'judul_formulir',
        'judul_rapat',
        'tanggal',
        'waktu_mulai',
        'waktu_selesai',
        'zona_waktu',
        'tempat',
        'notulis',
        'koordinator_smki',
        'created_by',
        'bidang_id',
        'tujuan',
        'tindak_lanjut',
    ];

    protected $casts = [
        'tanggal' => 'date',
        'tanggal_berlaku' => 'date',
    ];

    public function pesertas(): HasMany
    {
        return $this->hasMany(SmkiNotulenPeserta::class, 'smki_notulen_id')->orderBy('urutan', 'asc');
    }

    public function agendas(): HasMany
    {
        return $this->hasMany(SmkiNotulenAgenda::class, 'smki_notulen_id')->orderBy('nomor_agenda', 'asc');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function bidang(): BelongsTo
    {
        return $this->belongsTo(Bidang::class, 'bidang_id');
    }
}
