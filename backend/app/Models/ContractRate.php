<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $contract_id
 * @property int $version_no
 * @property string $rate_type
 * @property int|null $offshore_activity_type_id
 * @property string $amount
 * @property string $currency
 * @property string $unit
 * @property Carbon|null $effective_from
 * @property Carbon|null $effective_to
 */
class ContractRate extends Model
{
    public const TYPES = ['day_rate', 'hire_per_day', 'freight_per_mt', 'lump_sum', 'standby_day_rate', 'hourly', 'mobilization_fee', 'demobilization_fee', 'other'];

    public const UNITS = ['per_day', 'per_hour', 'per_mt', 'lump_sum'];

    /** Unit implied by each rate type (other → any). */
    public const UNIT_OF = ['day_rate' => 'per_day', 'hire_per_day' => 'per_day', 'standby_day_rate' => 'per_day', 'hourly' => 'per_hour',
        'freight_per_mt' => 'per_mt', 'lump_sum' => 'lump_sum', 'mobilization_fee' => 'lump_sum', 'demobilization_fee' => 'lump_sum'];

    protected $fillable = ['contract_id', 'version_no', 'rate_type', 'offshore_activity_type_id', 'description', 'amount', 'currency', 'unit',
        'effective_from', 'effective_to', 'notes'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:4', 'effective_from' => 'date', 'effective_to' => 'date', 'version_no' => 'integer'];
    }

    /** @return BelongsTo<OffshoreActivityType, $this> */
    public function activityType(): BelongsTo
    {
        return $this->belongsTo(OffshoreActivityType::class, 'offshore_activity_type_id');
    }

    /** @return array<string, mixed> canonical comparable form */
    public function canonical(): array
    {
        return [
            'rate_type' => $this->rate_type, 'offshore_activity_type_id' => $this->offshore_activity_type_id, 'description' => $this->getAttribute('description'),
            'amount' => $this->amount, 'currency' => $this->currency, 'unit' => $this->unit,
            'effective_from' => $this->effective_from?->toDateString(), 'effective_to' => $this->effective_to?->toDateString(), 'notes' => $this->getAttribute('notes'),
        ];
    }
}
