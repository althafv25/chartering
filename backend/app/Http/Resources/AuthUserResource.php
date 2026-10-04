<?php

namespace App\Http\Resources;

use App\Enums\Permission;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * The signed-in user with effective permissions (used by the SPA to show/hide
 * UI only — the API enforces every permission independently).
 *
 * @mixin User
 */
class AuthUserResource extends UserResource
{
    public function toArray(Request $request): array
    {
        $isSuperAdmin = $this->hasRole(UserRole::SuperAdmin->value);

        return [
            ...parent::toArray($request),
            'roles' => $this->getRoleNames()->values(),
            'is_super_admin' => $isSuperAdmin,
            'permissions' => $isSuperAdmin
                ? Permission::values()
                : $this->getAllPermissions()->pluck('name')->sort()->values(),
        ];
    }
}
