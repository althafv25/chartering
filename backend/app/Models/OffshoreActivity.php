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
 * @property string $activity_number
 * @property int $vessel_id
 * @property int|null $voyage_id
 * @property int|null $contract_id
 * @property int|null $offshore_project_id
 * @property int|null $client_company_id
 * @property int|null $offshore_location_id
 * @property int $offshore_activity_type_id
 * @property Carbon $start_at
 * @property Carbon $end_at
 * @property string $billable_hours
 * @property string $non_billable_hours
 * @property string $standby_hours
 * @property string|null $currency
 * @property list<array<string, mixed>>|null $rate_snapshot
 * @property string|null $revenue_amount
 * @property list<string>|null $warnings
 * @property list<array{fuel_type_id: int, mt: string}>|null $fuel_used
 * @property string $status
 * @property int|null $submitted_by
 */
class OffshoreActivity extends Model
{
    use HasAuditLog, HasLockVersion, SoftDeletes;

    public const STATUSES = ['draft', 'submitted', 'verified', 'invoiced'];

    protected $attributes = ['status' => 'draft', 'lock_version' => 0, 'billable_hours' => '0', 'non_billable_hours' => '0', 'standby_hours' => '0'];

    protected $fillable = ['activity_number', 'vessel_id', 'voyage_id', 'contract_id', 'offshore_project_id', 'client_company_id', 'offshore_location_id',
        'offshore_activity_type_id', 'start_at', 'end_at', 'description', 'billable_hours', 'non_billable_hours', 'standby_hours', 'currency',
        'contract_version_no', 'rate_snapshot', 'revenue_amount', 'calculation_basis', 'warnings', 'fuel_used', 'status', 'submitted_by', 'submitted_at',
        'verified_by', 'verified_at', 'decision_comment', 'remarks', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return ['start_at' => 'datetime', 'end_at' => 'datetime', 'submitted_at' => 'datetime', 'verified_at' => 'datetime', 'rate_snapshot' => 'array',
            'warnings' => 'array', 'fuel_used' => 'array', 'lock_version' => 'integer', 'contract_version_no' => 'integer'];
    }

    public function isEditable(): bool
    {
        return $this->status === 'draft';
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

    /** @return BelongsTo<Contract, $this> */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    /** @return BelongsTo<OffshoreProject, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(OffshoreProject::class, 'offshore_project_id');
    }

    /** @return BelongsTo<Company, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'client_company_id');
    }

    /** @return BelongsTo<OffshoreLocation, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(OffshoreLocation::class, 'offshore_location_id');
    }

    /** @return BelongsTo<OffshoreActivityType, $this> */
    public function type(): BelongsTo
    {
        return $this->belongsTo(OffshoreActivityType::class, 'offshore_activity_type_id');
    }

    /** @return BelongsTo<User, $this> */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /** @return BelongsTo<User, $this> */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
