<?php

namespace App\Policies;

class EnquiryPolicy extends PermissionPolicy
{
    protected function prefix(): string
    {
        return 'chartering.enquiries';
    }
}
