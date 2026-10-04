<?php

namespace App\Models;

class RevenueCategory extends ReferenceModel
{
    protected $table = 'revenue_categories';

    protected function casts(): array
    {
        return ['is_commissionable' => 'boolean'];
    }
}
