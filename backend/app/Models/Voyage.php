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
 * Voyage / operation shell (phase 4: created by conversion; lifecycle in phase 6).
 *
 * @property int $id
 * @property string $voyage_number
 * @property string $conversion_type
 * @property string $status
 * @property string $operation_type
 * @property string $currency
 * @property int $vessel_id
 * @property int|null $fixture_id
 * @property int|null $contract_id
 * @property Carbon|null $commenced_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $finalized_at
 * @property int $reopened_count
 * @property int|null $created_by
 * @property Carbon|null $created_at
 */
class Voyage extends Model
{
    use HasAuditLog, HasLockVersion, SoftDeletes;

    protected $attributes = ['status' => 'draft', 'lock_version' => 0];

    public const STATUSES = ['draft', 'nominated', 'mobilizing', 'loading', 'loaded', 'sailing', 'discharging', 'offshore_operation', 'standby',
        'demobilizing', 'completed', 'finalized', 'cancelled'];

    /** Operational moves (07 §6). completed/finalized/cancelled/reopen have dedicated actions with extra rules. */
    public const TRANSITIONS = [
        'draft' => ['nominated'],
        'nominated' => ['mobilizing', 'loading', 'sailing', 'offshore_operation'],
        'mobilizing' => ['loading', 'sailing', 'offshore_operation'],
        'loading' => ['loaded'],
        'loaded' => ['sailing'],
        'sailing' => ['loading', 'discharging', 'offshore_operation', 'demobilizing'],
        'discharging' => ['sailing', 'loading', 'demobilizing'],
        'offshore_operation' => ['standby', 'sailing', 'demobilizing'],
        'standby' => ['offshore_operation', 'sailing', 'demobilizing'],
        'demobilizing' => [],
    ];

    /** Statuses from which "complete" is allowed. */
    public const COMPLETABLE = ['sailing', 'discharging', 'offshore_operation', 'standby', 'demobilizing'];

    /** Before these the voyage has not commenced (BR-OP-03 proposal: commencement = first operational status). */
    public const PRE_COMMENCEMENT = ['draft', 'nominated'];

    /** No further status moves except finalize/reopen. */
    public const LOCKED = ['completed', 'finalized', 'cancelled'];

    protected $fillable = [
        'commenced_at', 'completed_at', 'finalized_at', 'finalized_by', 'finalization_waivers', 'cancelled_at', 'status_reason', 'reopened_count', 'status_changed_at',
        'voyage_number', 'vessel_id', 'fixture_id', 'contract_id', 'estimation_id', 'estimation_scenario_id', 'conversion_type', 'direct_reason',
        'operation_type', 'charterer_company_id', 'currency', 'status', 'remarks', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return ['lock_version' => 'integer', 'commenced_at' => 'datetime', 'completed_at' => 'datetime', 'finalized_at' => 'datetime',
            'cancelled_at' => 'datetime', 'status_changed_at' => 'datetime', 'reopened_count' => 'integer', 'finalization_waivers' => 'array'];
    }

    /** Operational records stay correctable until finalization (completed voyages may still be corrected). */
    public function isOperationallyOpen(): bool
    {
        return ! in_array($this->status, ['finalized', 'cancelled'], true);
    }

    /** @return BelongsTo<Company, $this> */
    public function charterer(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'charterer_company_id');
    }

    /** @return HasMany<PortCall, $this> */
    public function portCalls(): HasMany
    {
        return $this->hasMany(PortCall::class)->orderBy('sequence');
    }

    /** @return HasMany<VoyageMilestone, $this> */
    public function milestones(): HasMany
    {
        return $this->hasMany(VoyageMilestone::class)->orderByRaw('COALESCE(actual_at, planned_at)')->orderBy('id');
    }

    /** @return HasMany<OffHireEvent, $this> */
    public function offHires(): HasMany
    {
        return $this->hasMany(OffHireEvent::class)->orderBy('from_at');
    }

    /** @return HasMany<CaptainReport, $this> */
    public function captainReports(): HasMany
    {
        return $this->hasMany(CaptainReport::class)->orderBy('reported_at');
    }

    /** @return BelongsTo<Vessel, $this> */
    public function vessel(): BelongsTo
    {
        return $this->belongsTo(Vessel::class);
    }

    /** @return BelongsTo<Estimation, $this> */
    public function estimation(): BelongsTo
    {
        return $this->belongsTo(Estimation::class);
    }

    /** @return BelongsTo<EstimationScenario, $this> */
    public function scenario(): BelongsTo
    {
        return $this->belongsTo(EstimationScenario::class, 'estimation_scenario_id');
    }

    /** @return BelongsTo<Contract, $this> */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    /** @return BelongsTo<Fixture, $this> */
    public function fixture(): BelongsTo
    {
        return $this->belongsTo(Fixture::class);
    }

    /** @return HasMany<VoyageSnapshot, $this> */
    public function snapshots(): HasMany
    {
        return $this->hasMany(VoyageSnapshot::class)->orderBy('id');
    }

    /** @return HasMany<VoyageRevenue, $this> */
    public function voyageRevenues(): HasMany
    {
        return $this->hasMany(VoyageRevenue::class);
    }

    /** @return HasMany<VoyageExpense, $this> */
    public function voyageExpenses(): HasMany
    {
        return $this->hasMany(VoyageExpense::class);
    }

    /** @return HasMany<PortDa, $this> */
    public function portDas(): HasMany
    {
        return $this->hasMany(PortDa::class);
    }

    /** @return HasMany<LaytimeCalculation, $this> */
    public function laytimeCalculations(): HasMany
    {
        return $this->hasMany(LaytimeCalculation::class);
    }
}
