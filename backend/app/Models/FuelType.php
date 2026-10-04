<?php

namespace App\Models;

class FuelType extends ReferenceModel
{
    protected $table = 'fuel_types';

    protected function casts(): array
    {
        return ['is_eca_compliant' => 'boolean'];
    }
}
