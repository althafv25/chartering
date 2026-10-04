<?php

namespace App\Http\Resources;

use App\Models\UserNotification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin UserNotification */
class NotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'category' => $this->category,
            'title' => $this->title,
            'message' => $this->message,
            'action_url' => $this->action_url,
            'entity_type' => $this->entity_type,
            'entity_id' => $this->entity_id,
            'read_at' => $this->read_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
