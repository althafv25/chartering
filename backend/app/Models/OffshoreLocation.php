<?php

namespace App\Models;

use App\Models\Concerns\HasAuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Offshore field / platform / rig location (not a port).
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string $latitude
 * @property string $longitude
 * @property Carbon|null $created_at
 */
class OffshoreLocation extends Model
{
    use HasAuditLog, SoftDeletes;

    protected $attributes = ['status' => 'active', 'timezone' => 'UTC'];

    protected $fillable = [
        'code', 'name', 'field_name', 'block', 'operator_company_id', 'nearest_port_id', 'latitude', 'longitude',
        'water_depth_m', 'timezone', 'remarks', 'status', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return ['latitude' => 'decimal:7', 'longitude' => 'decimal:7', 'water_depth_m' => 'decimal:2'];
    }

    /** @return BelongsTo<Company, $this> */
    public function operator(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'operator_company_id');
    }

    /** @return BelongsTo<Port, $this> */
    public function nearestPort(): BelongsTo
    {
        return $this->belongsTo(Port::class, 'nearest_port_id');
    }
}
