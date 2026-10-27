<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmkiNotulenAgenda extends Model
{
    use HasFactory;

    protected $table = 'smki_notulen_agendas';

    protected $fillable = [
        'smki_notulen_id',
        'nomor_agenda',
        'agenda_item',
        'clause_iso',
        'outcomes',
        'actions_output',
        'pic',
        'tgl_penyelesaian',
    ];

    protected $casts = [
        'tgl_penyelesaian' => 'date',
    ];

    public function notulen(): BelongsTo
    {
        return $this->belongsTo(SmkiNotulen::class, 'smki_notulen_id');
    }
}
