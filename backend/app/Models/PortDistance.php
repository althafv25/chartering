<?php

namespace App\Models;

use App\Models\Concerns\HasAuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Cached sea distance between two points (port|location).
 *
 * @property int $id
 * @property string $from_type
 * @property int $from_id
 * @property string $to_type
 * @property int $to_id
 * @property string $route_key
 * @property string $distance_nm
 * @property string $eca_distance_nm
 * @property string $provider
 * @property Carbon $calculated_at
 */
class PortDistance extends Model
{
    use HasAuditLog;

    protected $fillable = ['from_type', 'from_id', 'to_type', 'to_id', 'route_key', 'distance_nm', 'eca_distance_nm', 'provider', 'notes', 'calculated_at', 'created_by'];

    protected function casts(): array
    {
        return ['distance_nm' => 'decimal:2', 'eca_distance_nm' => 'decimal:2', 'calculated_at' => 'datetime', 'from_id' => 'integer', 'to_id' => 'integer'];
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
