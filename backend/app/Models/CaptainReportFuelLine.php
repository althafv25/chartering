<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $fuel_type_id
 * @property string|null $rob_mt
 * @property string $consumed_mt
 * @property string $received_mt
 */
class CaptainReportFuelLine extends Model
{
    protected $fillable = ['captain_report_id', 'fuel_type_id', 'rob_mt', 'consumed_mt', 'received_mt'];

    /** @return BelongsTo<FuelType, $this> */
    public function fuelType(): BelongsTo
    {
        return $this->belongsTo(FuelType::class);
    }
}
