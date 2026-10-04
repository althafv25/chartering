<?php

namespace App\Models;

use App\Models\Concerns\HasAuditLog;
use App\Models\Concerns\HasLockVersion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $estimation_id
 * @property string $code
 * @property string $name
 * @property bool $is_selected
 * @property array<string, mixed> $vessel_snapshot
 * @property array<string, mixed> $inputs
 * @property string|null $inputs_hash
 * @property string $calc_status
 * @property list<string>|null $calc_issues
 * @property string|null $calculation_version
 * @property int|null $cloned_from_id
 * @property Carbon|null $calculated_at
 * @property Carbon|null $defaults_refreshed_at
 */
class EstimationScenario extends Model
{
    use HasAuditLog, HasLockVersion;

    protected $attributes = ['is_selected' => false, 'calc_status' => 'not_calculated', 'lock_version' => 0];

    protected $fillable = [
        'estimation_id', 'code', 'name', 'is_selected', 'vessel_snapshot', 'consumption_profile_id', 'inputs', 'inputs_hash',
        'calc_status', 'calc_issues', 'calculation_version', 'calculated_at', 'defaults_refreshed_at', 'cloned_from_id', 'notes',
        'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'is_selected' => 'boolean', 'vessel_snapshot' => 'array', 'inputs' => 'array', 'calc_issues' => 'array',
            'calculated_at' => 'datetime', 'defaults_refreshed_at' => 'datetime', 'lock_version' => 'integer',
        ];
    }

    /** Inputs are logged as a hash (full JSON would bloat the audit log). */
    protected function auditExcept(): array
    {
        return ['inputs', 'vessel_snapshot', 'calc_issues'];
    }

    /** @return BelongsTo<Estimation, $this> */
    public function estimation(): BelongsTo
    {
        return $this->belongsTo(Estimation::class);
    }

    /** @return HasOne<ScenarioResultRecord, $this> */
    public function result(): HasOne
    {
        return $this->hasOne(ScenarioResultRecord::class, 'scenario_id');
    }

    /** @return BelongsTo<EstimationScenario, $this> */
    public function clonedFrom(): BelongsTo
    {
        return $this->belongsTo(EstimationScenario::class, 'cloned_from_id');
    }

    public function isCurrent(): bool
    {
        return $this->calc_status === 'calculated';
    }
}
