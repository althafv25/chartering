<?php

namespace App\Models;

use App\Enums\UserStatus;
use App\Models\Concerns\HasAuditLog;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property UserStatus $status
 * @property Carbon|null $last_login_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasAuditLog, HasFactory, HasRoles, Notifiable, SoftDeletes;

    protected string $guard_name = 'web';

    /** @var list<string> */
    protected $fillable = [
        'name',
        'first_name',
        'last_name',
        'email',
        'password',
        'phone',
        'job_title',
        'status',
        'timezone',
        'password_changed_at',
        'created_by',
        'updated_by',
    ];

    /** @var list<string> */
    protected $hidden = [
        'password',
        'remember_token',
        'last_login_ip',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password_changed_at' => 'datetime',
            'password' => 'hashed',
            'status' => UserStatus::class,
        ];
    }

    // ── Relationships ──────────────────────────────────────────

    /** @return HasMany<UserNotification, $this> */
    public function appNotifications(): HasMany
    {
        return $this->hasMany(UserNotification::class);
    }

    // ── Helpers ────────────────────────────────────────────────

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }

    /** Last-login fields are written without touching the audit log. */
    public function recordLogin(?string $ip): void
    {
        $this->forceFill(['last_login_at' => now(), 'last_login_ip' => $ip])->saveQuietly();
    }
}
