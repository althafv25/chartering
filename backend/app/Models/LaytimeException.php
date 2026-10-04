<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $laytime_calculation_id
 * @property Carbon $from_at
 * @property Carbon $to_at
 * @property string $exception_type
 * @property string $pct_counted
 * @property string|null $remarks
 */
class LaytimeException extends Model
{
    protected $fillable = ['laytime_calculation_id', 'from_at', 'to_at', 'exception_type', 'pct_counted', 'remarks'];

    protected $attributes = ['pct_counted' => '0'];

    protected function casts(): array
    {
        return ['from_at' => 'datetime', 'to_at' => 'datetime'];
    }

    /** @return BelongsTo<LaytimeCalculation, $this> */
    public function laytimeCalculation(): BelongsTo
    {
        return $this->belongsTo(LaytimeCalculation::class);
    }
}
