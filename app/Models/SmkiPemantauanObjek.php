<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SmkiPemantauanObjek extends Model
{
    protected $table = 'smki_pemantauan_objeks';

    protected $guarded = ['id'];

    public function pemantauan(): BelongsTo
    {
        return $this->belongsTo(SmkiPemantauan::class, 'pemantauan_id');
    }

    public function objek(): BelongsTo
    {
        return $this->belongsTo(SmkiObjek::class, 'objek_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SmkiKonfigurasiItem::class, 'pemantauan_objek_id');
    }
}
