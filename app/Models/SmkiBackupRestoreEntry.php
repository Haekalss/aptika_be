<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SmkiBackupRestoreEntry extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['month' => 'integer', 'performed_on' => 'date:Y-m-d'];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(SmkiBackupRestoreAsset::class, 'asset_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(SmkiBackupRestoreAttachment::class, 'entry_id');
    }
}
