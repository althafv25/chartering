<?php

namespace App\Models;

use App\Exceptions\BusinessRuleException;
use App\Models\Concerns\HasAuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * One commercial position in a negotiation. Immutable once sent/received:
 * only the lifecycle columns may change afterwards (enforced here as a last
 * line of defence, in addition to OfferService).
 *
 * @property int $id
 * @property int $offer_id
 * @property int $revision_no
 * @property string $direction
 * @property string $status
 * @property int|null $estimation_scenario_id
 * @property string $rate
 * @property string $rate_basis
 * @property string $currency
 * @property string|null $quantity
 * @property array<int, mixed> $ports
 * @property array<string, mixed> $commissions
 * @property Carbon|null $laycan_from
 * @property Carbon|null $laycan_to
 * @property Carbon|null $valid_until
 * @property Carbon|null $sent_at
 * @property Carbon|null $received_at
 * @property Carbon|null $decided_at
 * @property Carbon|null $created_at
 */
class OfferRevision extends Model
{
    use HasAuditLog;

    public const COMMERCIAL_FIELDS = ['direction', 'estimation_scenario_id', 'rate', 'rate_basis', 'currency', 'quantity', 'quantity_unit',
        'laycan_from', 'laycan_to', 'period_days', 'ports', 'commissions', 'terms', 'valid_until', 'remarks'];

    private const LIFECYCLE_FIELDS = ['status', 'sent_at', 'received_at', 'decided_at', 'decision_reason', 'decided_by', 'updated_at'];

    protected $attributes = ['status' => 'draft'];

    protected $fillable = [
        'offer_id', 'revision_no', 'direction', 'status', 'estimation_scenario_id', 'rate', 'rate_basis', 'currency', 'quantity', 'quantity_unit',
        'laycan_from', 'laycan_to', 'period_days', 'ports', 'commissions', 'terms', 'valid_until', 'remarks', 'sent_at', 'received_at',
        'decided_at', 'decision_reason', 'decided_by', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:4', 'quantity' => 'decimal:3', 'period_days' => 'decimal:2', 'ports' => 'array', 'commissions' => 'array',
            'laycan_from' => 'date', 'laycan_to' => 'date', 'valid_until' => 'date', 'sent_at' => 'datetime', 'received_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $rev) {
            if ($rev->getOriginal('status') !== 'draft') {
                $illegal = array_diff(array_keys($rev->getDirty()), self::LIFECYCLE_FIELDS);
                if ($illegal) {
                    throw new BusinessRuleException('Sent or received offer revisions are immutable. Create a new revision instead.', 'revision_immutable');
                }
            }
        });
        static::deleting(function (self $rev) {
            if ($rev->status !== 'draft') {
                throw new BusinessRuleException('Only draft revisions can be deleted.', 'revision_immutable');
            }
        });
    }

    /** @return BelongsTo<Offer, $this> */
    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    /** @return BelongsTo<EstimationScenario, $this> */
    public function scenario(): BelongsTo
    {
        return $this->belongsTo(EstimationScenario::class, 'estimation_scenario_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasOne<Fixture, $this> */
    public function fixture(): HasOne
    {
        return $this->hasOne(Fixture::class);
    }

    public function isImmutable(): bool
    {
        return $this->status !== 'draft';
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['sent', 'received'], true);
    }
}
