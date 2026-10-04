<?php

namespace App\Policies;

class CompanyPolicy extends PermissionPolicy
{
    protected function prefix(): string
    {
        return 'companies';
    }
}
