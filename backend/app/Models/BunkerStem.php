<?php

namespace App\Models;

use App\Models\Concerns\HasAuditLog;
use App\Models\Concerns\HasLockVersion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $stem_number
 * @property int $vessel_id
 * @property int|null $voyage_id
 * @property int|null $port_call_id
 * @property int $fuel_type_id
 * @property Carbon $ordered_on
 * @property string $ordered_mt
 * @property Carbon|null $delivered_at
 * @property string|null $delivered_mt
 * @property string $price_per_mt
 * @property string $currency
 * @property string|null $total_amount
 * @property string $status
 */
class BunkerStem extends Model
{
    use HasAuditLog, HasLockVersion, SoftDeletes;

    public const STATUSES = ['ordered', 'delivered', 'invoiced', 'cancelled'];

    protected $attributes = ['status' => 'ordered', 'lock_version' => 0];

    protected $fillable = ['stem_number', 'vessel_id', 'voyage_id', 'port_call_id', 'port_id', 'supplier_company_id', 'fuel_type_id', 'ordered_on', 'ordered_mt',
        'delivered_at', 'delivered_mt', 'price_per_mt', 'currency', 'fx_rate', 'fx_method', 'total_amount', 'base_amount', 'bdn_number', 'invoice_reference',
        'status', 'remarks', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return ['ordered_on' => 'date', 'delivered_at' => 'datetime', 'lock_version' => 'integer'];
    }

    /** @return BelongsTo<Vessel, $this> */
    public function vessel(): BelongsTo
    {
        return $this->belongsTo(Vessel::class);
    }

    /** @return BelongsTo<Voyage, $this> */
    public function voyage(): BelongsTo
    {
        return $this->belongsTo(Voyage::class);
    }

    /** @return BelongsTo<PortCall, $this> */
    public function portCall(): BelongsTo
    {
        return $this->belongsTo(PortCall::class);
    }

    /** @return BelongsTo<Port, $this> */
    public function port(): BelongsTo
    {
        return $this->belongsTo(Port::class);
    }

    /** @return BelongsTo<Company, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'supplier_company_id');
    }

    /** @return BelongsTo<FuelType, $this> */
    public function fuelType(): BelongsTo
    {
        return $this->belongsTo(FuelType::class);
    }
}
