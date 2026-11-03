<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SmkiRemovableMediaReport extends Model
{
    use SoftDeletes;

    public const TEMPLATE = [
        'code' => 'FR-025/KOM.03.05/ SANDIKAMI',
        'revision' => '1.0',
        'effective_date' => '2022-07-07',
    ];

    public const CONDITIONS = ['baik' => 'Baik', 'kurang_baik' => 'Kurang Baik', 'rusak' => 'Rusak'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['tanggal_dokumen' => 'date:Y-m-d', 'tanggal_berlaku' => 'date:Y-m-d', 'finalized_at' => 'datetime', 'version' => 'integer'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(SmkiRemovableMediaItem::class, 'report_id')->orderBy('urutan');
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if (! $user->isAdminAptika()) {
            $query->where('bidang_id', $user->bidang_id);
            if ($user->role !== 'admin') {
                $query->where('created_by', $user->id);
            }
        }

        return $query;
    }
}
