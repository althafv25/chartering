<?php

namespace App\Models;

use App\Enums\NotificationType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * In-app notification for one user.
 *
 * @property int $id
 * @property int $user_id
 * @property NotificationType $type
 * @property Carbon|null $read_at
 */
class UserNotification extends Model
{
    protected $fillable = [
        'user_id', 'type', 'category', 'title', 'message', 'action_url',
        'entity_type', 'entity_id', 'dedupe_key', 'read_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => NotificationType::class,
            'read_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @param Builder<UserNotification> $query */
    public function scopeUnread(Builder $query): void
    {
        $query->whereNull('read_at');
    }
}
