<?php

namespace App\Policies;

use App\Models\User;

class EstimationPolicy extends PermissionPolicy
{
    protected function prefix(): string
    {
        return 'chartering.estimations';
    }

    public function submit(User $user, mixed $model = null): bool
    {
        return $user->can('chartering.estimations.submit');
    }

    public function approve(User $user, mixed $model = null): bool
    {
        return $user->can('chartering.estimations.approve');
    }

    public function reject(User $user, mixed $model = null): bool
    {
        return $user->can('chartering.estimations.reject');
    }

    public function clone(User $user, mixed $model = null): bool
    {
        return $user->can('chartering.estimations.clone') && $user->can('chartering.estimations.create');
    }
}
