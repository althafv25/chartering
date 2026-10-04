<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $alias
 * @property Carbon|null $valid_to
 */
class CompanyAlias extends Model
{
    protected $fillable = ['company_id', 'alias', 'normalized_alias', 'reason', 'valid_to', 'created_by'];

    protected function casts(): array
    {
        return ['valid_to' => 'date'];
    }
}
