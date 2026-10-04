<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use App\Models\UserNotification;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Notifications are always scoped to the authenticated user; another user's
 * notification id resolves to 404, never 200/403.
 */
class NotificationController extends Controller
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate(['unread' => ['nullable', 'boolean']]);

        $items = UserNotification::query()
            ->where('user_id', $request->user()->id)
            ->when($request->boolean('unread'), fn ($q) => $q->whereNull('read_at'))
            ->latest('id')
            ->paginate($this->perPage($request, 20));

        return $this->ok(NotificationResource::collection($items));
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $count = UserNotification::query()->where('user_id', $request->user()->id)->whereNull('read_at')->count();

        return $this->ok(['count' => $count]);
    }

    public function markRead(Request $request, int $id): JsonResponse
    {
        $notification = UserNotification::query()->where('user_id', $request->user()->id)->findOrFail($id);

        return $this->ok(new NotificationResource($this->notifications->markRead($notification)), 'Marked as read.');
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $count = $this->notifications->markAllRead($request->user());

        return $this->ok(['updated' => $count], 'All notifications marked as read.');
    }
}
