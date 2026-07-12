<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\Concerns\HasAccessControl;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'phone', 'job_title', 'avatar', 'status', 'password', 'last_login_at', 'report_prefs'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasAccessControl, HasApiTokens, HasFactory, Notifiable;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_LOCKED = 'locked';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'report_prefs' => 'array',
        ];
    }

    public function isLocked(): bool
    {
        return $this->status === self::STATUS_LOCKED;
    }

    public function initials(): string
    {
        return collect(explode(' ', trim($this->name)))
            ->map(fn ($word) => mb_substr($word, 0, 1))
            ->take(2)
            ->implode('');
    }
}
