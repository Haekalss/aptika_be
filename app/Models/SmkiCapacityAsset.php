<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SmkiCapacityAsset extends Model
{
    public const CATEGORIES = ['server' => 'Server', 'network' => 'Network'];

    public const DOCUMENTS = [
        'server' => 'FR-026/KOM.03.05/SANDIKAMI',
        'network' => 'FR-029/KOM.03.05/SANDIKAMI',
    ];

    public const TEMPLATE = [
        'revision' => '1.0',
        'effective_date' => '2022-07-07',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'bidang_id' => 'integer',
            'created_by' => 'integer',
        ];
    }

    public function logs(): HasMany
    {
        return $this->hasMany(SmkiCapacityLog::class, 'asset_id')->orderBy('year')->orderBy('month');
    }

    public function bidang(): BelongsTo
    {
        return $this->belongsTo(Bidang::class, 'bidang_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if (! $user->isAdminAptika()) {
            $query->where('bidang_id', $user->bidang_id);
        }

        return $query;
    }
}
