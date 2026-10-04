<?php

namespace App\Policies;

class VoyagePolicy extends PermissionPolicy
{
    protected function prefix(): string
    {
        return 'operations.voyages';
    }
}
