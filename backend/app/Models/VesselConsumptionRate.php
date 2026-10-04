<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $mode
 * @property string $speed_kn
 * @property int $fuel_type_id
 * @property string $consumption_mt_per_day
 */
class VesselConsumptionRate extends Model
{
    protected $fillable = ['profile_id', 'mode', 'speed_kn', 'fuel_type_id', 'consumption_mt_per_day'];

    protected function casts(): array
    {
        return ['speed_kn' => 'decimal:2', 'consumption_mt_per_day' => 'decimal:3'];
    }

    /** @return BelongsTo<FuelType, $this> */
    public function fuelType(): BelongsTo
    {
        return $this->belongsTo(FuelType::class);
    }
}
