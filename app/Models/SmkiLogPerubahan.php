<?php

namespace App\Models;

use App\Traits\BelongsToBidang;
use Illuminate\Database\Eloquent\Model;

class SmkiLogPerubahan extends Model
{
    use BelongsToBidang;

    protected $table = 'smki_log_perubahan';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'bidang_id' => 'integer',
            'recorder_id' => 'integer',
            'object_id' => 'integer',
            'executor_id' => 'integer',
            'payload' => 'array',
            'finalized_at' => 'datetime',
        ];
    }
}
