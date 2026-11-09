<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmkiBackupRestoreAttachment extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['disk', 'path'];

    public function entry(): BelongsTo
    {
        return $this->belongsTo(SmkiBackupRestoreEntry::class, 'entry_id');
    }
}
