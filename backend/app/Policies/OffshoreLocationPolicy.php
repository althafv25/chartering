<?php

namespace App\Policies;

class OffshoreLocationPolicy extends PermissionPolicy
{
    protected function prefix(): string
    {
        return 'ports';
    }
}
