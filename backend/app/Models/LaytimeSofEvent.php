<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $laytime_calculation_id
 * @property Carbon $event_at
 * @property string $event_code
 * @property string|null $description
 * @property string $source
 */
class LaytimeSofEvent extends Model
{
    protected $fillable = ['laytime_calculation_id', 'event_at', 'event_code', 'description', 'source'];

    protected $attributes = ['source' => 'manual'];

    protected function casts(): array
    {
        return ['event_at' => 'datetime'];
    }

    /** @return BelongsTo<LaytimeCalculation, $this> */
    public function laytimeCalculation(): BelongsTo
    {
        return $this->belongsTo(LaytimeCalculation::class);
    }
}
