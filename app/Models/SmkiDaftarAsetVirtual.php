<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SmkiDaftarAsetVirtual extends Model
{
    use SoftDeletes;

    protected $table = 'smki_daftar_aset_virtuals';

    protected $guarded = ['id'];

    public function instances(): HasMany
    {
        return $this->hasMany(SmkiDaftarAsetVirtualInstance::class, 'daftar_aset_virtual_id')
            ->orderBy('urutan');
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isAdminAptika()) {
            return $query;
        }

        $query->where('bidang_id', $user->bidang_id);

        if ($user->role !== 'admin') {
            $query->where('created_by', $user->id);
        }

        return $query;
    }
}
