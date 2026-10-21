<?php

namespace App\Models;

use App\Models\Concerns\HasAuditLog;
use App\Models\Concerns\HasLockVersion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The attained CII for one vessel in one reporting year, computed against one
 * formula set. draft (inputs being entered) → calculated (CiiCalculator ran clean)
 * → verified (a different user confirms it for filing). Recalculating a verified
 * record is refused — correct the inputs and clone a new year, or reopen explicitly.
 *
 * @property int $id
 * @property int $vessel_id
 * @property int $reporting_year
 * @property int $formula_set_id
 * @property string $capacity_metric
 * @property string $capacity_value
 * @property array<string, string> $fuel_consumption
 * @property string $distance_sailed_nm
 * @property string|null $attained_cii
 * @property string|null $required_cii
 * @property string|null $rating
 * @property string $source
 * @property string $scope_status
 * @property string $status
 */
class CiiVesselYear extends Model
{
    use HasAuditLog, HasLockVersion, SoftDeletes;

    public const STATUSES = ['draft', 'calculated', 'verified'];

    public const SCOPE_STATUSES = ['in_scope', 'exempt'];

    public const RATINGS = ['A', 'B', 'C', 'D', 'E'];

    protected $table = 'cii_vessel_years';

    protected $attributes = ['status' => 'draft', 'source' => 'calculated', 'scope_status' => 'in_scope', 'lock_version' => 0];

    protected $fillable = [
        'vessel_id', 'reporting_year', 'formula_set_id', 'capacity_metric', 'capacity_value', 'fuel_consumption', 'distance_sailed_nm',
        'attained_cii', 'required_cii', 'rating', 'source', 'scope_status', 'status', 'calculation_trace', 'calculation_version',
        'verified_by_id', 'verified_at', 'remarks', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'fuel_consumption' => 'array',
            'calculation_trace' => 'array',
            'capacity_value' => 'decimal:3',
            'distance_sailed_nm' => 'decimal:2',
            'attained_cii' => 'decimal:6',
            'required_cii' => 'decimal:6',
            'verified_at' => 'datetime',
            'lock_version' => 'integer',
        ];
    }

    /** @return BelongsTo<Vessel, $this> */
    public function vessel(): BelongsTo
    {
        return $this->belongsTo(Vessel::class);
    }

    /** @return BelongsTo<CiiFormulaSet, $this> */
    public function formulaSet(): BelongsTo
    {
        return $this->belongsTo(CiiFormulaSet::class, 'formula_set_id');
    }

    /** @return BelongsTo<User, $this> */
    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_id');
    }
}
