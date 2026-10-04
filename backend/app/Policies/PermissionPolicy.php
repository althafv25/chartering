<?php

namespace App\Policies;

use App\Models\User;

/**
 * Permission-prefix policy: viewAny/view → {prefix}.view, create → .create, ...
 * Record-state rules (e.g. finalized voyages) belong in services.
 */
abstract class PermissionPolicy
{
    abstract protected function prefix(): string;

    public function viewAny(User $user): bool
    {
        return $user->can($this->prefix().'.view');
    }

    public function view(User $user, mixed $model = null): bool
    {
        return $user->can($this->prefix().'.view');
    }

    public function create(User $user): bool
    {
        return $user->can($this->prefix().'.create');
    }

    public function update(User $user, mixed $model = null): bool
    {
        return $user->can($this->prefix().'.update');
    }

    public function delete(User $user, mixed $model = null): bool
    {
        return $user->can($this->prefix().'.delete');
    }
}
