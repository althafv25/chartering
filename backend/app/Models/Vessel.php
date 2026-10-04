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
 * @property string $code
 * @property string $name
 * @property string|null $imo_number
 * @property int $vessel_type_id
 * @property array<string, mixed>|null $custom_attributes
 * @property string|null $commercial_status
 * @property string|null $operational_status
 * @property string $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Vessel extends Model
{
    use HasAuditLog, HasLockVersion, SoftDeletes;

    /** Decimal columns and their scale (also drives validation & resources). */
    public const DECIMALS = [
        'loa_m' => 3, 'lbp_m' => 3, 'beam_m' => 3, 'depth_m' => 3, 'summer_draft_m' => 3, 'air_draft_m' => 3,
        'dwt_mt' => 3, 'gt' => 2, 'nt' => 2,
        'main_engine_power_kw' => 2, 'aux_engine_power_kw' => 2,
        'service_speed_kn' => 2, 'max_speed_kn' => 2, 'eco_speed_kn' => 2,
        'deck_area_m2' => 2, 'deck_strength_t_m2' => 2, 'bollard_pull_t' => 2, 'crane_swl_t' => 2,
    ];

    protected $attributes = ['status' => 'active', 'ownership_type' => 'owned', 'lock_version' => 0];

    protected $fillable = [
        'code', 'name', 'imo_number', 'mmsi', 'call_sign', 'official_number', 'vessel_type_id', 'subtype',
        'flag_country', 'port_of_registry', 'year_built', 'builder', 'class_society', 'class_notation', 'ownership_type',
        'owner_company_id', 'manager_company_id', 'commercial_manager_company_id', 'technical_manager_company_id',
        'loa_m', 'lbp_m', 'beam_m', 'depth_m', 'summer_draft_m', 'air_draft_m', 'dwt_mt', 'gt', 'nt',
        'main_engine', 'main_engine_power_kw', 'aux_engines', 'aux_engine_power_kw', 'propulsion',
        'service_speed_kn', 'max_speed_kn', 'eco_speed_kn',
        'deck_area_m2', 'deck_strength_t_m2', 'bollard_pull_t', 'dp_class', 'crane_swl_t', 'crew_capacity', 'passenger_capacity',
        'custom_attributes', 'crew_management_vessel_id', 'status', 'remarks', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        $casts = [
            'custom_attributes' => 'array',
            'year_built' => 'integer',
            'crew_capacity' => 'integer',
            'passenger_capacity' => 'integer',
            'lock_version' => 'integer',
        ];
        foreach (self::DECIMALS as $col => $scale) {
            $casts[$col] = "decimal:{$scale}";
        }

        return $casts;
    }

    /** @return BelongsTo<VesselType, $this> */
    public function vesselType(): BelongsTo
    {
        return $this->belongsTo(VesselType::class);
    }

    /** @return BelongsTo<Company, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'owner_company_id');
    }

    /** @return BelongsTo<Company, $this> */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'manager_company_id');
    }

    /** @return BelongsTo<Company, $this> */
    public function commercialManager(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'commercial_manager_company_id');
    }

    /** @return BelongsTo<Company, $this> */
    public function technicalManager(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'technical_manager_company_id');
    }

    /** @return HasMany<VesselConsumptionProfile, $this> */
    public function consumptionProfiles(): HasMany
    {
        return $this->hasMany(VesselConsumptionProfile::class)->orderByDesc('is_default')->orderByDesc('effective_from');
    }

    /** @return HasMany<VesselStatusHistory, $this> */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(VesselStatusHistory::class)->orderByDesc('effective_from')->orderByDesc('id');
    }

    /** @return HasMany<VesselNameHistory, $this> */
    public function nameHistory(): HasMany
    {
        return $this->hasMany(VesselNameHistory::class)->orderByDesc('valid_to');
    }
}
