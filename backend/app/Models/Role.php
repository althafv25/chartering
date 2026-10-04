<?php

namespace App\Models;

use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Pins roles to the `web` guard. API requests authenticate through the
 * `sanctum` guard, which has no user provider of its own; without this,
 * Spatie would resolve the guard from the current request.
 */
class Role extends SpatieRole
{
    protected $guard_name = 'web';
}
