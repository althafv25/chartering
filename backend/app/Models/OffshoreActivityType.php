<?php

namespace App\Models;

class OffshoreActivityType extends ReferenceModel
{
    protected $table = 'offshore_activity_types';

    protected function casts(): array
    {
        return ['is_billable_default' => 'boolean'];
    }
}
