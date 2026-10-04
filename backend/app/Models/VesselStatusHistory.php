<?php

namespace App\Models;

use App\Models\Concerns\HasAuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $vessel_id
 * @property string $track
 * @property string $status
 * @property Carbon $effective_from
 * @property Carbon|null $effective_to
 */
class VesselStatusHistory extends Model
{
    use HasAuditLog;

    protected $table = 'vessel_status_history';

    protected $fillable = [
        'vessel_id', 'track', 'status', 'effective_from', 'effective_to', 'port_id', 'offshore_location_id',
        'location_text', 'latitude', 'longitude', 'reason', 'remarks', 'changed_by',
    ];

    protected function casts(): array
    {
        return ['effective_from' => 'datetime', 'effective_to' => 'datetime', 'latitude' => 'decimal:7', 'longitude' => 'decimal:7'];
    }

    /** @return BelongsTo<Port, $this> */
    public function port(): BelongsTo
    {
        return $this->belongsTo(Port::class);
    }

    /** @return BelongsTo<OffshoreLocation, $this> */
    public function offshoreLocation(): BelongsTo
    {
        return $this->belongsTo(OffshoreLocation::class);
    }

    /** @return BelongsTo<User, $this> */
    public function changer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    public function locationLabel(): ?string
    {
        if ($this->port_id && $this->port) {
            return $this->port->label();
        }
        if ($this->offshore_location_id && $this->offshoreLocation) {
            return $this->offshoreLocation->name;
        }

        return $this->location_text;
    }
}
