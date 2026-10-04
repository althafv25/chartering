<?php

namespace App\Policies;

use App\Models\User;

class OfferPolicy extends PermissionPolicy
{
    protected function prefix(): string
    {
        return 'chartering.offers';
    }

    public function send(User $user, mixed $model = null): bool
    {
        return $user->can('chartering.offers.send');
    }

    public function accept(User $user, mixed $model = null): bool
    {
        return $user->can('chartering.offers.accept');
    }

    public function reject(User $user, mixed $model = null): bool
    {
        return $user->can('chartering.offers.reject');
    }
}
