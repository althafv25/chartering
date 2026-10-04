<?php

namespace App\Http\Resources;

use App\Enums\UserRole;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Role */
class RoleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $system = UserRole::tryFrom($this->name);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'label' => $system?->label() ?? ucwords(str_replace('-', ' ', $this->name)),
            'is_system' => $system !== null,
            'is_locked' => $system === UserRole::SuperAdmin,
            'users_count' => $this->whenCounted('users'),
            'permissions' => $this->whenLoaded('permissions', fn () => $this->permissions->pluck('name')->sort()->values()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
