<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $name
 * @property Carbon $valid_to
 */
class VesselNameHistory extends Model
{
    protected $fillable = ['vessel_id', 'name', 'valid_to', 'changed_by'];

    protected function casts(): array
    {
        return ['valid_to' => 'date'];
    }
}
