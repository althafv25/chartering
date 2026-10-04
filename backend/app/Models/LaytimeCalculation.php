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
 * @property int $id
 * @property int $port_call_id
 * @property int $voyage_id
 * @property int|null $contract_id
 * @property string $calculation_type
 * @property string|null $fixed_hours
 * @property string|null $cargo_quantity
 * @property string|null $rate_per_day
 * @property string|null $rate_unit
 * @property string|null $terms_code
 * @property array|null $terms_definition
 * @property Carbon|null $nor_tendered_at
 * @property Carbon|null $nor_accepted_at
 * @property string|null $notice_time_hours
 * @property Carbon|null $laytime_commenced_at
 * @property Carbon|null $laytime_completed_at
 * @property string|null $demurrage_rate_per_day
 * @property string|null $despatch_rate_per_day
 * @property string|null $currency
 * @property string $once_on_demurrage_rule
 * @property string|null $allowed_hours
 * @property string|null $used_hours
 * @property string|null $difference_hours
 * @property string|null $demurrage_amount
 * @property string|null $despatch_amount
 * @property string|null $calculation_version
 * @property array|null $trace
 * @property Carbon|null $calculated_at
 * @property string $status
 * @property Carbon|null $submitted_at
 * @property int|null $submitted_by
 * @property Carbon|null $agreed_at
 * @property int|null $agreed_by
 * @property string|null $remarks
 */
class LaytimeCalculation extends Model
{
    use HasAuditLog, HasLockVersion, SoftDeletes;

    public const TYPES = ['load', 'discharge', 'reversible'];

    public const STATUSES = ['draft', 'submitted', 'agreed', 'disputed'];

    protected $attributes = ['status' => 'draft', 'once_on_demurrage_rule' => 'always_on_demurrage', 'lock_version' => 0];

    protected $fillable = ['port_call_id', 'voyage_id', 'contract_id', 'calculation_type', 'fixed_hours', 'cargo_quantity', 'rate_per_day', 'rate_unit',
        'terms_code', 'terms_definition', 'nor_tendered_at', 'nor_accepted_at', 'notice_time_hours', 'laytime_commenced_at', 'laytime_completed_at',
        'demurrage_rate_per_day', 'despatch_rate_per_day', 'currency', 'once_on_demurrage_rule', 'allowed_hours', 'used_hours', 'difference_hours',
        'demurrage_amount', 'despatch_amount', 'calculation_version', 'trace', 'calculated_at', 'status', 'submitted_at', 'submitted_by', 'agreed_at',
        'agreed_by', 'remarks', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return [
            'terms_definition' => 'array',
            'trace' => 'array',
            'nor_tendered_at' => 'datetime',
            'nor_accepted_at' => 'datetime',
            'laytime_commenced_at' => 'datetime',
            'laytime_completed_at' => 'datetime',
            'calculated_at' => 'datetime',
            'submitted_at' => 'datetime',
            'agreed_at' => 'datetime',
            'lock_version' => 'integer',
        ];
    }

    /** @return BelongsTo<PortCall, $this> */
    public function portCall(): BelongsTo
    {
        return $this->belongsTo(PortCall::class);
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

    /** @return HasMany<LaytimeSofEvent, $this> */
    public function sofEvents(): HasMany
    {
        return $this->hasMany(LaytimeSofEvent::class)->orderBy('event_at');
    }

    /** @return HasMany<LaytimeException, $this> */
    public function exceptions(): HasMany
    {
        return $this->hasMany(LaytimeException::class)->orderBy('from_at');
    }

    public function isEditable(): bool
    {
        return in_array($this->status, ['draft'], true);
    }
}
