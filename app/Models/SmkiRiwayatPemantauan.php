<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmkiRiwayatPemantauan extends Model
{
    protected $table = 'smki_riwayat_pemantauans';

    public $timestamps = true;

    public const CREATED_AT = 'created_at';
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'siklus' => 'integer',
            'snapshot' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function pemantauan(): BelongsTo
    {
        return $this->belongsTo(SmkiPemantauan::class, 'pemantauan_id');
    }
}
