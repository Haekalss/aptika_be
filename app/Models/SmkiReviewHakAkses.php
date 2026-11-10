<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// FR-034 is shared across bidang. Master names are retained as document snapshots.
class SmkiReviewHakAkses extends Model
{
    protected $table = 'smki_review_hak_akses';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'bidang_id' => 'integer', 'recorder_id' => 'integer'];
    }
}
