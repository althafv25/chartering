<?php

namespace App\Policies;

use App\Models\User;

class ContractPolicy extends PermissionPolicy
{
    protected function prefix(): string
    {
        return 'contracts';
    }

    public function delete(User $user, mixed $model = null): bool
    {
        return false; // contracts are cancelled, never deleted
    }

    public function submit(User $user, mixed $model = null): bool
    {
        return $user->can('contracts.submit');
    }

    public function approve(User $user, mixed $model = null): bool
    {
        return $user->can('contracts.approve');
    }

    public function activate(User $user, mixed $model = null): bool
    {
        return $user->can('contracts.activate');
    }

    public function cancel(User $user, mixed $model = null): bool
    {
        return $user->can('contracts.cancel');
    }

    public function amend(User $user, mixed $model = null): bool
    {
        return $user->can('contracts.amend');
    }
}
