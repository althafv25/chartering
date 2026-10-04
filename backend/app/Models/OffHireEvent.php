<?php

namespace App\Models;

use App\Models\Concerns\HasAuditLog;
use App\Models\Concerns\HasLockVersion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $voyage_id
 * @property Carbon $from_at
 * @property Carbon|null $to_at
 * @property string $reason_code
 * @property string|null $hours
 * @property list<array{fuel_type_id: int, mt: string}>|null $fuel_consumed
 * @property string $status
 */
class OffHireEvent extends Model
{
    use HasAuditLog, HasLockVersion;

    public const REASONS = ['breakdown', 'deficiency', 'dry_dock', 'repairs', 'crew', 'weather', 'detention', 'other'];

    public const STATUSES = ['draft', 'agreed', 'disputed'];

    protected $attributes = ['status' => 'draft', 'lock_version' => 0];

    protected $fillable = ['voyage_id', 'from_at', 'to_at', 'reason_code', 'description', 'hours', 'fuel_consumed', 'status',
        'decided_by', 'decided_at', 'decision_comment', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return ['from_at' => 'datetime', 'to_at' => 'datetime', 'decided_at' => 'datetime', 'fuel_consumed' => 'array', 'lock_version' => 'integer'];
    }

    /** @return BelongsTo<Voyage, $this> */
    public function voyage(): BelongsTo
    {
        return $this->belongsTo(Voyage::class);
    }

    /** @return BelongsTo<User, $this> */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
