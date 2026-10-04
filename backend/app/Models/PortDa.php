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
 * @property string $da_number
 * @property int $port_call_id
 * @property int $voyage_id
 * @property int $port_id
 * @property int|null $agent_company_id
 * @property string $da_type
 * @property int|null $proforma_da_id
 * @property string $currency
 * @property string|null $fx_rate
 * @property string|null $fx_method
 * @property string $total_amount
 * @property string $base_amount
 * @property string $status
 * @property Carbon|null $submitted_at
 * @property int|null $submitted_by
 * @property Carbon|null $approved_at
 * @property int|null $approved_by
 * @property string|null $remarks
 */
class PortDa extends Model
{
    use HasAuditLog, HasLockVersion, SoftDeletes;

    public const TYPES = ['proforma', 'final'];

    public const STATUSES = ['draft', 'submitted', 'approved', 'settled'];

    protected $table = 'port_das';

    protected $attributes = ['da_type' => 'proforma', 'status' => 'draft', 'total_amount' => '0', 'base_amount' => '0', 'lock_version' => 0];

    protected $fillable = ['da_number', 'port_call_id', 'voyage_id', 'port_id', 'agent_company_id', 'da_type', 'proforma_da_id', 'currency', 'fx_rate', 'fx_method',
        'total_amount', 'base_amount', 'status', 'submitted_at', 'submitted_by', 'approved_at', 'approved_by', 'remarks', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return ['submitted_at' => 'datetime', 'approved_at' => 'datetime', 'lock_version' => 'integer'];
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

    /** @return BelongsTo<Port, $this> */
    public function port(): BelongsTo
    {
        return $this->belongsTo(Port::class);
    }

    /** @return BelongsTo<Company, $this> */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'agent_company_id');
    }

    /** @return BelongsTo<self, $this> */
    public function proforma(): BelongsTo
    {
        return $this->belongsTo(self::class, 'proforma_da_id');
    }

    /** @return HasMany<PortDaItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(PortDaItem::class)->orderBy('sequence');
    }

    public function isEditable(): bool
    {
        return in_array($this->status, ['draft'], true);
    }
}
