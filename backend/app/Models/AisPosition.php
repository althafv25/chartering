<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One reported vessel position. Append-only: rows are never edited (no audit log, high volume).
 *
 * @property int $id
 * @property int $vessel_id
 * @property string $latitude
 * @property string $longitude
 * @property Carbon $observed_at
 * @property string $provider
 * @property string|null $sog_kn
 * @property string|null $cog_deg
 * @property string|null $draught_m
 * @property Carbon $received_at
 * @property Carbon|null $eta_reported
 */
class AisPosition extends Model
{
    protected $fillable = ['vessel_id', 'imo', 'mmsi', 'latitude', 'longitude', 'sog_kn', 'cog_deg', 'heading_deg', 'nav_status', 'destination',
        'eta_reported', 'draught_m', 'observed_at', 'received_at', 'provider', 'recorded_by', 'raw'];

    protected function casts(): array
    {
        return [
            'latitude' => 'string', 'longitude' => 'string', 'sog_kn' => 'string', 'cog_deg' => 'string', 'draught_m' => 'string',
            'heading_deg' => 'integer', 'observed_at' => 'datetime', 'received_at' => 'datetime', 'eta_reported' => 'datetime', 'raw' => 'array',
        ];
    }

    /** @return BelongsTo<Vessel, $this> */
    public function vessel(): BelongsTo
    {
        return $this->belongsTo(Vessel::class);
    }
}
