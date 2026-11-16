<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SmkiPemantauan extends Model
{
    use SoftDeletes;

    protected $table = 'smki_pemantauans';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'tanggal_berlaku' => 'date',
            'tanggal_pemantauan' => 'date',
            'tanggal_ttd_pelaksana' => 'datetime',
            'tanggal_ttd_admin' => 'datetime',
            'jumlah_sesuai' => 'integer',
            'jumlah_tidak_sesuai' => 'integer',
            'siklus' => 'integer',
            'version' => 'integer',
        ];
    }

    public function jenis(): BelongsTo
    {
        return $this->belongsTo(SmkiJenisKonfigurasi::class, 'jenis_konfigurasi_id');
    }

    public function pelaksana(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pelaksana_id');
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    public function objeks(): HasMany
    {
        return $this->hasMany(SmkiPemantauanObjek::class, 'pemantauan_id');
    }

    public function riwayat(): HasMany
    {
        return $this->hasMany(SmkiRiwayatPemantauan::class, 'pemantauan_id');
    }

    /**
     * Visibilitas FR-048: semua admin melihat seluruh pemantauan,
     * pelaksana hanya miliknya.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->role === 'admin') {
            return $query;
        }
        return $query->where('pelaksana_id', $user->id);
    }
}
