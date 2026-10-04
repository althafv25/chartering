<?php

namespace App\Policies;

class PortPolicy extends PermissionPolicy
{
    protected function prefix(): string
    {
        return 'ports';
    }
}
