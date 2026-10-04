<?php

namespace App\Policies;

use App\Models\User;

class FixturePolicy extends PermissionPolicy
{
    protected function prefix(): string
    {
        return 'chartering.fixtures';
    }

    public function submit(User $user, mixed $model = null): bool
    {
        return $user->can('chartering.fixtures.submit');
    }

    public function approve(User $user, mixed $model = null): bool
    {
        return $user->can('chartering.fixtures.approve');
    }

    public function cancel(User $user, mixed $model = null): bool
    {
        return $user->can('chartering.fixtures.cancel');
    }
}
