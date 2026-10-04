<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Role;
use App\Models\User;

class RolePolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermissionTo(Permission::RolesView->value);
    }

    public function view(User $actor, Role $role): bool
    {
        return $actor->hasPermissionTo(Permission::RolesView->value);
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermissionTo(Permission::RolesCreate->value);
    }

    public function update(User $actor, Role $role): bool
    {
        return $actor->hasPermissionTo(Permission::RolesUpdate->value);
    }

    public function delete(User $actor, Role $role): bool
    {
        return $actor->hasPermissionTo(Permission::RolesDelete->value);
    }
}
