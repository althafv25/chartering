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
 * @property int $client_company_id
 * @property int|null $contract_id
 * @property string $status
 * @property Carbon|null $start_date
 * @property Carbon|null $end_date
 */
class OffshoreProject extends Model
{
    use HasAuditLog, HasLockVersion, SoftDeletes;

    public const STATUSES = ['planned', 'active', 'completed', 'cancelled'];

    protected $attributes = ['status' => 'planned', 'lock_version' => 0];

    protected $fillable = ['code', 'name', 'client_company_id', 'contract_id', 'offshore_location_id', 'field_name', 'start_date', 'end_date', 'status',
        'remarks', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return ['start_date' => 'date', 'end_date' => 'date', 'lock_version' => 'integer'];
    }

    /** @return BelongsTo<Company, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'client_company_id');
    }

    /** @return BelongsTo<Contract, $this> */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    /** @return BelongsTo<OffshoreLocation, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(OffshoreLocation::class, 'offshore_location_id');
    }

    /** @return HasMany<OffshoreActivity, $this> */
    public function activities(): HasMany
    {
        return $this->hasMany(OffshoreActivity::class);
    }
}
