<?php

namespace App\Models;

use App\Models\Concerns\HasAuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $voyage_id
 * @property int|null $port_call_id
 * @property int $milestone_type_id
 * @property Carbon|null $planned_at
 * @property Carbon|null $actual_at
 * @property string $source
 * @property Carbon|null $verified_at
 */
class VoyageMilestone extends Model
{
    use HasAuditLog;

    protected $attributes = ['source' => 'manual'];

    protected $fillable = ['voyage_id', 'port_call_id', 'milestone_type_id', 'planned_at', 'actual_at', 'source', 'captain_report_id',
        'verified_by', 'verified_at', 'remarks', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return ['planned_at' => 'datetime', 'actual_at' => 'datetime', 'verified_at' => 'datetime'];
    }

    /** @return BelongsTo<MilestoneType, $this> */
    public function type(): BelongsTo
    {
        return $this->belongsTo(MilestoneType::class, 'milestone_type_id');
    }

    /** @return BelongsTo<PortCall, $this> */
    public function portCall(): BelongsTo
    {
        return $this->belongsTo(PortCall::class);
    }

    /** @return BelongsTo<User, $this> */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
