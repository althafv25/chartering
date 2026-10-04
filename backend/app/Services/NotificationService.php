<?php

namespace App\Services;

use App\Enums\NotificationType;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;

/**
 * In-app notifications. A dedupe key (e.g. "contract-expiry:42:30d") makes
 * scheduled checks idempotent: the same alert is never created twice per user.
 */
class NotificationService
{
    public function send(
        User $user,
        string $category,
        string $title,
        string $message,
        NotificationType $type = NotificationType::Info,
        ?string $actionUrl = null,
        ?string $entityType = null,
        ?int $entityId = null,
        ?string $dedupeKey = null,
    ): ?UserNotification {
        if ($dedupeKey !== null && UserNotification::query()
            ->where('user_id', $user->id)->where('dedupe_key', $dedupeKey)->exists()) {
            return null;
        }

        try {
            return UserNotification::query()->create([
                'user_id' => $user->id,
                'type' => $type,
                'category' => $category,
                'title' => $title,
                'message' => $message,
                'action_url' => $actionUrl,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'dedupe_key' => $dedupeKey,
            ]);
        } catch (QueryException $e) {
            // Concurrent duplicate (unique user_id+dedupe_key) — treat as already sent.
            if ($dedupeKey !== null && str_contains($e->getMessage(), 'Duplicate')) {
                return null;
            }
            throw $e;
        }
    }

    /**
     * @param  iterable<User>  $users
     * @return Collection<int, UserNotification>
     */
    public function sendToMany(iterable $users, string $category, string $title, string $message, NotificationType $type = NotificationType::Info, ?string $actionUrl = null, ?string $entityType = null, ?int $entityId = null, ?string $dedupeKey = null): Collection
    {
        $sent = collect();
        foreach ($users as $user) {
            $n = $this->send($user, $category, $title, $message, $type, $actionUrl, $entityType, $entityId, $dedupeKey);
            if ($n) {
                $sent->push($n);
            }
        }

        return $sent;
    }

    /** Users who hold a permission (directly, via role, or as super-admin). */
    public function usersWithPermission(string $permission): Collection
    {
        return User::query()->where('status', 'active')->get()
            ->filter(fn (User $u) => $u->can($permission))->values();
    }

    public function markRead(UserNotification $notification): UserNotification
    {
        if ($notification->read_at === null) {
            $notification->forceFill(['read_at' => now()])->save();
        }

        return $notification;
    }

    public function markAllRead(User $user): int
    {
        return UserNotification::query()->where('user_id', $user->id)->whereNull('read_at')->update(['read_at' => now()]);
    }
}
