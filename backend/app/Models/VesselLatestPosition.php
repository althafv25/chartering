<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $vessel_id
 * @property int $ais_position_id
 * @property Carbon $observed_at
 * @property bool $is_stale
 */
class VesselLatestPosition extends Model
{
    protected $fillable = ['vessel_id', 'ais_position_id', 'observed_at', 'is_stale'];

    protected function casts(): array
    {
        return ['observed_at' => 'datetime', 'is_stale' => 'boolean'];
    }

    /** @return BelongsTo<Vessel, $this> */
    public function vessel(): BelongsTo
    {
        return $this->belongsTo(Vessel::class);
    }

    /** @return BelongsTo<AisPosition, $this> */
    public function position(): BelongsTo
    {
        return $this->belongsTo(AisPosition::class, 'ais_position_id');
    }
}
