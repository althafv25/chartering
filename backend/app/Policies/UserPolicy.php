<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;

class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermissionTo(Permission::UsersView->value);
    }

    public function view(User $actor, User $user): bool
    {
        return $actor->is($user) || $actor->hasPermissionTo(Permission::UsersView->value);
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermissionTo(Permission::UsersCreate->value);
    }

    public function update(User $actor, User $user): bool
    {
        return $actor->hasPermissionTo(Permission::UsersUpdate->value);
    }

    public function delete(User $actor, User $user): bool
    {
        return $actor->hasPermissionTo(Permission::UsersDelete->value);
    }
}
