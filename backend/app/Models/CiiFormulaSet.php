<?php

namespace App\Models;

use App\Models\Concerns\HasAuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A versioned set of IMO CII parameters (reference lines, reduction factors, rating
 * boundaries, emission factors). Starts life as `draft`; Marine Operations marks it
 * `verified` once the figures are confirmed against the applicable IMO resolution
 * (docs/CII-REQUIREMENTS.md BR-CII-02). A superseded set is `retired`, never deleted,
 * so past vessel-year calculations stay reproducible.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property array<string, mixed> $parameters
 * @property Carbon $effective_date
 * @property Carbon|null $expiration_date
 * @property string $status
 */
class CiiFormulaSet extends Model
{
    use HasAuditLog;

    public const STATUSES = ['draft', 'verified', 'retired'];

    protected $table = 'cii_formula_sets';

    protected $attributes = ['status' => 'draft'];

    protected $fillable = ['code', 'name', 'parameters', 'effective_date', 'expiration_date', 'status', 'remarks', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return ['parameters' => 'array', 'effective_date' => 'date', 'expiration_date' => 'date'];
    }

    /** @return HasMany<CiiVesselYear, $this> */
    public function vesselYears(): HasMany
    {
        return $this->hasMany(CiiVesselYear::class);
    }
}
