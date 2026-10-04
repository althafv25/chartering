<?php

namespace App\Models;

use App\Models\Concerns\HasAuditLog;
use App\Models\Concerns\HasLockVersion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Chartering enquiry / RFQ.
 *
 * @property int $id
 * @property string $enquiry_number
 * @property string $business_type
 * @property string $status
 * @property string|null $currency
 * @property string|null $quantity
 * @property string|null $rate_idea
 * @property Carbon $received_at
 * @property Carbon|null $laycan_from
 * @property Carbon|null $laycan_to
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Enquiry extends Model
{
    use HasAuditLog, HasLockVersion, SoftDeletes;

    public const BUSINESS_TYPES = ['voyage_charter', 'time_charter', 'offshore_charter', 'cargo_relet', 'service'];

    public const STATUSES = ['open', 'evaluating', 'offered', 'fixed', 'lost', 'cancelled'];

    protected $attributes = ['status' => 'open', 'source' => 'direct', 'lock_version' => 0];

    protected $fillable = [
        'enquiry_number', 'received_at', 'source', 'business_type', 'charterer_company_id', 'broker_company_id', 'cargo_type_id',
        'cargo_description', 'quantity', 'quantity_unit', 'quantity_tolerance_pct', 'offshore_location_id', 'laycan_from', 'laycan_to',
        'period_days', 'rate_idea', 'rate_basis', 'currency', 'commission_terms', 'terms', 'remarks', 'status', 'lost_reason',
        'assigned_to', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime', 'laycan_from' => 'date', 'laycan_to' => 'date',
            'quantity' => 'decimal:3', 'quantity_tolerance_pct' => 'decimal:4', 'period_days' => 'decimal:2', 'rate_idea' => 'decimal:4',
            'lock_version' => 'integer',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function charterer(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'charterer_company_id');
    }

    /** @return BelongsTo<Company, $this> */
    public function broker(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'broker_company_id');
    }

    /** @return BelongsTo<CargoType, $this> */
    public function cargoType(): BelongsTo
    {
        return $this->belongsTo(CargoType::class);
    }

    /** @return BelongsTo<OffshoreLocation, $this> */
    public function offshoreLocation(): BelongsTo
    {
        return $this->belongsTo(OffshoreLocation::class);
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /** @return HasMany<EnquiryPort, $this> */
    public function ports(): HasMany
    {
        return $this->hasMany(EnquiryPort::class)->orderBy('sequence');
    }

    /** @return HasMany<EnquiryVessel, $this> */
    public function vessels(): HasMany
    {
        return $this->hasMany(EnquiryVessel::class);
    }

    /** @return HasMany<Estimation, $this> */
    public function estimations(): HasMany
    {
        return $this->hasMany(Estimation::class);
    }

    /** @return HasMany<Offer, $this> */
    public function offers(): HasMany
    {
        return $this->hasMany(Offer::class);
    }

    public function isClosed(): bool
    {
        return in_array($this->status, ['fixed', 'lost', 'cancelled'], true);
    }
}
