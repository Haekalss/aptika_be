<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SmkiBackupRestoreReport extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['tahun' => 'integer', 'version' => 'integer', 'tanggal_terbit' => 'date:Y-m-d', 'finalized_at' => 'datetime'];
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where('bidang_id', $user->bidang_id);
    }

    public function assets(): HasMany
    {
        return $this->hasMany(SmkiBackupRestoreAsset::class, 'report_id')->orderBy('urutan');
    }
}
