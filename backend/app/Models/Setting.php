<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $key
 * @property string|null $value
 * @property string $type
 * @property string $group
 */
class Setting extends Model
{
    protected $fillable = ['key', 'value', 'type', 'group', 'updated_by'];
}
