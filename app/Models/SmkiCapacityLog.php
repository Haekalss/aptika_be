<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmkiCapacityLog extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'asset_id' => 'integer',
            'year' => 'integer',
            'month' => 'integer',
            'current_storage_gb' => 'float',
            'current_memory_pct' => 'float',
            'current_cpu_pct' => 'float',
            'projection_storage_gb' => 'float',
            'projection_memory_pct' => 'float',
            'projection_cpu_pct' => 'float',
            'bandwidth_capacity_mbps' => 'float',
            'bandwidth_max_usage_mbps' => 'float',
            'sla_agreement_pct' => 'float',
            'sla_achievement_pct' => 'float',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(SmkiCapacityAsset::class, 'asset_id');
    }
}
