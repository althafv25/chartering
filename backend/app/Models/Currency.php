<?php

namespace App\Models;

use App\Models\Concerns\HasAuditLog;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $code
 * @property int $decimals
 */
class Currency extends Model
{
    use HasAuditLog;

    protected $attributes = ['status' => 'active', 'decimals' => 2];

    protected $fillable = ['code', 'name', 'symbol', 'decimals', 'status'];

    protected function casts(): array
    {
        return ['decimals' => 'integer'];
    }

    protected static function booted(): void
    {
        static::saving(fn (self $c) => $c->code = strtoupper($c->code));
    }
}
