<?php

namespace App\Models;

use App\Models\Concerns\HasAuditLog;
use App\Models\Concerns\HasLockVersion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $estimation_number
 * @property int|null $enquiry_id
 * @property string $estimation_type
 * @property int $vessel_id
 * @property string $currency
 * @property string $status
 * @property int|null $submitted_by
 * @property Carbon|null $submitted_at
 * @property Carbon|null $decided_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Estimation extends Model
{
    use HasAuditLog, HasLockVersion, SoftDeletes;

    public const TYPES = ['voyage_charter', 'time_charter', 'offshore_day_rate', 'cargo_relet'];

    protected $attributes = ['status' => 'draft', 'lock_version' => 0];

    protected $fillable = [
        'estimation_number', 'enquiry_id', 'estimation_type', 'title', 'vessel_id', 'currency', 'status', 'submitted_by', 'submitted_at',
        'decided_by', 'decided_at', 'decision_comment', 'cloned_from_id', 'remarks', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return ['submitted_at' => 'datetime', 'decided_at' => 'datetime', 'lock_version' => 'integer'];
    }

    /** @return BelongsTo<Enquiry, $this> */
    public function enquiry(): BelongsTo
    {
        return $this->belongsTo(Enquiry::class);
    }

    /** @return BelongsTo<Vessel, $this> */
    public function vessel(): BelongsTo
    {
        return $this->belongsTo(Vessel::class);
    }

    /** @return HasMany<EstimationScenario, $this> */
    public function scenarios(): HasMany
    {
        return $this->hasMany(EstimationScenario::class)->orderBy('id');
    }

    /** @return HasOne<EstimationScenario, $this> */
    public function selectedScenario(): HasOne
    {
        return $this->hasOne(EstimationScenario::class)->where('is_selected', true);
    }

    /** @return BelongsTo<User, $this> */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /** @return BelongsTo<User, $this> */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /** @return BelongsTo<Estimation, $this> */
    public function clonedFrom(): BelongsTo
    {
        return $this->belongsTo(Estimation::class, 'cloned_from_id');
    }

    public function isEditable(): bool
    {
        return $this->status === 'draft';
    }
}
