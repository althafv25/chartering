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
 * Charter / service contract. Commercial terms are versioned: rates and
 * clauses carry version_no; v1 is the approved original, v2+ come only from
 * approved amendments (CT-02). Earlier versions are never modified.
 *
 * @property int $id
 * @property string $contract_number
 * @property string $contract_type
 * @property int|null $fixture_id
 * @property int $customer_company_id
 * @property int|null $vessel_id
 * @property string $currency
 * @property string $status
 * @property int $current_version
 * @property int|null $submitted_by
 * @property array<string, mixed> $commissions
 * @property Carbon|null $start_date
 * @property Carbon|null $end_date
 * @property Carbon|null $submitted_at
 * @property Carbon|null $decided_at
 * @property Carbon|null $activated_at
 * @property Carbon|null $closed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Contract extends Model
{
    use HasAuditLog, HasLockVersion, SoftDeletes;

    public const TYPES = ['voyage_charter', 'time_charter', 'bareboat', 'offshore_charter', 'service', 'other'];

    public const STATUSES = ['draft', 'under_review', 'approved', 'active', 'completed', 'expired', 'cancelled'];

    /** Header fields that amendments may change (currency/customer/type/vessel are fixed for the contract's life). */
    public const AMENDABLE = ['end_date', 'extension_options', 'payment_terms_days', 'payment_terms_text', 'commissions', 'terms', 'title'];

    protected $attributes = ['status' => 'draft', 'current_version' => 1, 'lock_version' => 0];

    protected $fillable = [
        'contract_number', 'contract_type', 'title', 'fixture_id', 'customer_company_id', 'vessel_id', 'start_date', 'end_date',
        'extension_options', 'currency', 'payment_terms_days', 'payment_terms_text', 'commissions', 'terms', 'remarks', 'status',
        'current_version', 'submitted_by', 'submitted_at', 'decided_by', 'decided_at', 'decision_comment', 'activated_at', 'closed_at',
        'close_reason', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date', 'end_date' => 'date', 'commissions' => 'array', 'payment_terms_days' => 'integer', 'current_version' => 'integer',
            'submitted_at' => 'datetime', 'decided_at' => 'datetime', 'activated_at' => 'datetime', 'closed_at' => 'datetime', 'lock_version' => 'integer',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'customer_company_id');
    }

    /** @return BelongsTo<Vessel, $this> */
    public function vessel(): BelongsTo
    {
        return $this->belongsTo(Vessel::class);
    }

    /** @return BelongsTo<Fixture, $this> */
    public function fixture(): BelongsTo
    {
        return $this->belongsTo(Fixture::class);
    }

    /** @return HasMany<ContractRate, $this> */
    public function rates(): HasMany
    {
        return $this->hasMany(ContractRate::class)->orderBy('version_no')->orderBy('rate_type')->orderBy('id');
    }

    /** @return HasMany<ContractClause, $this> */
    public function clauses(): HasMany
    {
        return $this->hasMany(ContractClause::class)->orderBy('version_no')->orderBy('sequence');
    }

    /** @return HasMany<ContractVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(ContractVersion::class)->orderBy('version_no');
    }

    /** @return HasMany<ContractAmendment, $this> */
    public function amendments(): HasMany
    {
        return $this->hasMany(ContractAmendment::class)->orderByDesc('amendment_no');
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

    public function isEditable(): bool
    {
        return $this->status === 'draft';
    }

    public function isAmendable(): bool
    {
        return in_array($this->status, ['approved', 'active'], true);
    }
}
