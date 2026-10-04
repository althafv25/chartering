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
 * @property int $vessel_id
 * @property int|null $voyage_id
 * @property int|null $port_call_id
 * @property string $report_type
 * @property Carbon $reported_at
 * @property string $status
 * @property int|null $submitted_by
 * @property string|null $distance_since_last_nm
 */
class CaptainReport extends Model
{
    use HasAuditLog, HasLockVersion, SoftDeletes;

    public const TYPES = ['noon', 'arrival', 'departure', 'daily', 'bunker', 'offshore_activity'];

    public const STATUSES = ['draft', 'submitted', 'verified', 'rejected'];

    /** Fields editable while the report is draft or rejected. */
    public const DATA_FIELDS = ['report_type', 'reported_at', 'latitude', 'longitude', 'port_call_id', 'speed_kn', 'course_deg', 'distance_since_last_nm',
        'distance_to_go_nm', 'wind_force_bft', 'wind_direction', 'sea_state', 'weather_text', 'main_engine_hours', 'aux_engine_hours', 'activity_text',
        'delay_hours', 'delay_reason', 'remarks'];

    protected $attributes = ['status' => 'draft', 'source' => 'manual', 'lock_version' => 0];

    protected $fillable = ['vessel_id', 'voyage_id', 'port_call_id', 'report_type', 'reported_at', 'latitude', 'longitude', 'speed_kn', 'course_deg',
        'distance_since_last_nm', 'distance_to_go_nm', 'wind_force_bft', 'wind_direction', 'sea_state', 'weather_text', 'main_engine_hours',
        'aux_engine_hours', 'activity_text', 'delay_hours', 'delay_reason', 'remarks', 'source', 'status', 'submitted_by', 'submitted_at',
        'verified_by', 'verified_at', 'decision_comment', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return ['reported_at' => 'datetime', 'submitted_at' => 'datetime', 'verified_at' => 'datetime', 'lock_version' => 'integer',
            'course_deg' => 'integer', 'wind_force_bft' => 'integer'];
    }

    public function isEditable(): bool
    {
        return in_array($this->status, ['draft', 'rejected'], true);
    }

    /** @return BelongsTo<Vessel, $this> */
    public function vessel(): BelongsTo
    {
        return $this->belongsTo(Vessel::class);
    }

    /** @return BelongsTo<Voyage, $this> */
    public function voyage(): BelongsTo
    {
        return $this->belongsTo(Voyage::class);
    }

    /** @return BelongsTo<PortCall, $this> */
    public function portCall(): BelongsTo
    {
        return $this->belongsTo(PortCall::class);
    }

    /** @return HasMany<CaptainReportFuelLine, $this> */
    public function fuelLines(): HasMany
    {
        return $this->hasMany(CaptainReportFuelLine::class)->orderBy('fuel_type_id');
    }

    /** @return BelongsTo<User, $this> */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /** @return BelongsTo<User, $this> */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
