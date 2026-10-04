<?php

namespace App\Models;

use App\Models\Concerns\HasAuditLog;
use App\Models\Concerns\HasLockVersion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Commercial recap snapshot created from an accepted offer revision.
 *
 * @property int $id
 * @property string $fixture_number
 * @property int $offer_revision_id
 * @property int $estimation_scenario_id
 * @property array<string, mixed> $recap_snapshot
 * @property string $status
 * @property string $rate
 * @property string $rate_basis
 * @property string $currency
 * @property string $business_type
 * @property int $vessel_id
 * @property int $enquiry_id
 * @property int|null $submitted_by
 * @property array<string, mixed> $commissions
 * @property Carbon|null $submitted_at
 * @property Carbon|null $decided_at
 * @property Carbon $fixture_date
 * @property Carbon|null $laycan_from
 * @property Carbon|null $laycan_to
 * @property Carbon|null $created_at
 */
class Fixture extends Model
{
    use HasAuditLog, HasLockVersion, SoftDeletes;

    public const STATUSES = ['draft', 'submitted', 'approved', 'failed', 'cancelled'];

    protected $attributes = ['status' => 'draft', 'lock_version' => 0];

    protected $fillable = [
        'fixture_number', 'offer_revision_id', 'estimation_scenario_id', 'enquiry_id', 'vessel_id', 'charterer_company_id', 'owner_company_id',
        'broker_company_id', 'fixture_date', 'business_type', 'cargo_description', 'quantity', 'quantity_unit', 'laycan_from', 'laycan_to',
        'rate', 'rate_basis', 'currency', 'ports', 'commissions', 'terms', 'remarks', 'recap_snapshot', 'status', 'submitted_by', 'submitted_at',
        'decided_by', 'decided_at', 'decision_comment', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return ['fixture_date' => 'date', 'laycan_from' => 'date', 'laycan_to' => 'date', 'rate' => 'decimal:4', 'quantity' => 'decimal:3',
            'ports' => 'array', 'commissions' => 'array', 'recap_snapshot' => 'array', 'submitted_at' => 'datetime', 'decided_at' => 'datetime',
            'lock_version' => 'integer'];
    }

    protected function auditExcept(): array
    {
        return ['recap_snapshot'];
    }

    /** @return BelongsTo<Vessel, $this> */
    public function vessel(): BelongsTo
    {
        return $this->belongsTo(Vessel::class);
    }

    /** @return BelongsTo<Enquiry, $this> */
    public function enquiry(): BelongsTo
    {
        return $this->belongsTo(Enquiry::class);
    }

    /** @return BelongsTo<OfferRevision, $this> */
    public function revision(): BelongsTo
    {
        return $this->belongsTo(OfferRevision::class, 'offer_revision_id');
    }

    /** @return HasOne<Contract, $this> */
    public function contract(): HasOne
    {
        return $this->hasOne(Contract::class);
    }

    /** @return HasOne<Voyage, $this> */
    public function voyage(): HasOne
    {
        return $this->hasOne(Voyage::class);
    }

    /** @return BelongsTo<EstimationScenario, $this> */
    public function scenario(): BelongsTo
    {
        return $this->belongsTo(EstimationScenario::class, 'estimation_scenario_id');
    }

    /** @return BelongsTo<User, $this> */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /** @return BelongsTo<User, $this> */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /** @return BelongsTo<Company, $this> */
    public function charterer(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'charterer_company_id');
    }
}
