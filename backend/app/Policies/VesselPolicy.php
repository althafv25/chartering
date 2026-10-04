<?php

namespace App\Policies;

class VesselPolicy extends PermissionPolicy
{
    protected function prefix(): string
    {
        return 'vessels';
    }
}
