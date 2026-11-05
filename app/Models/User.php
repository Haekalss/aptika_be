<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'position',
        'phone',
        'avatar',
        'password',
        'role',
        'is_active',
        'bidang_id',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $appends = [
        'avatar_url',
        'jabatan',
        'no_telp',
    ];

    public function getJabatanAttribute(): ?string
    {
        return $this->position;
    }

    public function getNoTelpAttribute(): ?string
    {
        return $this->phone;
    }

    public function getAvatarUrlAttribute(): ?string
    {
        if (!$this->avatar) {
            return null;
        }

        if (
            str_starts_with($this->avatar, 'http://') ||
            str_starts_with($this->avatar, 'https://')
        ) {
            return $this->avatar;
        }

        if (str_starts_with($this->avatar, 'data:image/')) {
            return $this->avatar;
        }

        $cleanPath = ltrim($this->avatar, '/');

        try {
            if (request() && request()->header('Host')) {
                return request()->schemeAndHttpHost() . '/storage/' . $cleanPath;
            }
        } catch (\Throwable $e) {
            // Fallback if request is not available (e.g. CLI commands)
        }

        return asset('storage/' . $cleanPath);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'integer',
        ];
    }

    public function boardsCreated()
    {
        return $this->hasMany(Board::class, 'created_by');
    }

    public function boardMemberships()
    {
        return $this->hasMany(BoardMember::class);
    }

    public function assignedTasks()
    {
        return $this->hasMany(Task::class, 'assigned_to');
    }

    public function createdTasks()
    {
        return $this->hasMany(Task::class, 'created_by');
    }

    public function taskComments()
    {
        return $this->hasMany(TaskComment::class);
    }

    public function uploadedAttachments()
    {
        return $this->hasMany(TaskAttachment::class, 'uploaded_by');
    }

    public function taskActivities()
    {
        return $this->hasMany(TaskActivity::class);
    }

    public function notifications()
    {
        return $this->hasMany(Notification::class, 'user_id');
    }

    public function sentNotifications()
    {
        return $this->hasMany(Notification::class, 'created_by_user_id');
    }

    public function spesimenTte()
    {
        return $this->hasOne(\App\Models\SmkiSpesimenTte::class, 'user_id');
    }

    /**
     * Relasi ke tabel bidangs (unit kerja pengguna).
     */
    public function bidang()
    {
        return $this->belongsTo(Bidang::class, 'bidang_id');
    }

    /**
     * Cek apakah user adalah Admin Aptika (satu-satunya role yang bisa mengakses Admin Panel).
     */
    public function isAdminAptika(): bool
    {
        return $this->role === 'admin' && $this->bidang?->code === 'APTIKA';
    }
}