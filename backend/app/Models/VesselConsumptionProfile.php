<?php

namespace App\Models;

use App\Models\Concerns\HasAuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Versioned consumption assumptions. Estimations copy (snapshot) the values;
 * editing a profile never changes existing estimations.
 *
 * @property int $id
 * @property int $vessel_id
 * @property string $name
 * @property Carbon $effective_from
 * @property Carbon|null $effective_to
 * @property bool $is_default
 */
class VesselConsumptionProfile extends Model
{
    use HasAuditLog;

    protected $attributes = ['source' => 'design', 'is_default' => false];

    protected $fillable = ['vessel_id', 'name', 'source', 'effective_from', 'effective_to', 'is_default', 'remarks', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return ['effective_from' => 'date', 'effective_to' => 'date', 'is_default' => 'boolean'];
    }

    /** @return BelongsTo<Vessel, $this> */
    public function vessel(): BelongsTo
    {
        return $this->belongsTo(Vessel::class);
    }

    /** @return HasMany<VesselConsumptionRate, $this> */
    public function rates(): HasMany
    {
        return $this->hasMany(VesselConsumptionRate::class, 'profile_id')->orderBy('mode')->orderBy('speed_kn');
    }
}
