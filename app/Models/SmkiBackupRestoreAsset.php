<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SmkiBackupRestoreAsset extends Model
{
    protected $guarded = ['id'];

    public function report(): BelongsTo
    {
        return $this->belongsTo(SmkiBackupRestoreReport::class, 'report_id');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(SmkiBackupRestoreEntry::class, 'asset_id')->orderBy('month')->orderBy('operation');
    }
}
