<?php

namespace App\Models;

/**
 * @property list<array{key:string,label:string,data_type:string,unit?:string|null,options?:list<string>}>|null $attribute_schema
 */
class VesselType extends ReferenceModel
{
    protected $table = 'vessel_types';

    protected function casts(): array
    {
        return ['attribute_schema' => 'array'];
    }
}
