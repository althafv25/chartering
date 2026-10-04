<?php

namespace App\Models;

use Spatie\Permission\Models\Permission as SpatiePermission;

/** Pinned to the `web` guard (see Role). */
class Permission extends SpatiePermission
{
    protected $guard_name = 'web';
}
