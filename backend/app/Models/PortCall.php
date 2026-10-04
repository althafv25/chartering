<?php

namespace App\Models;

use App\Models\Concerns\HasAuditLog;
use App\Models\Concerns\HasLockVersion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $voyage_id
 * @property int $sequence
 * @property int|null $port_id
 * @property int|null $offshore_location_id
 * @property string $purpose
 * @property string $status
 * @property Carbon|null $eta
 * @property Carbon|null $etb
 * @property Carbon|null $etd
 * @property Carbon|null $ata
 * @property Carbon|null $atb
 * @property Carbon|null $atd
 */
class PortCall extends Model
{
    use HasAuditLog, HasLockVersion;

    public const PURPOSES = ['load', 'discharge', 'bunker', 'supply', 'crew_change', 'repair', 'offshore_ops', 'other'];

    public const STATUSES = ['planned', 'nominated', 'arrived', 'berthed', 'sailed', 'cancelled'];

    public const TIMES = ['eta', 'etb', 'etd', 'ata', 'atb', 'atd'];

    protected $attributes = ['status' => 'planned', 'lock_version' => 0];

    protected $fillable = ['voyage_id', 'sequence', 'port_id', 'offshore_location_id', 'agent_company_id', 'purpose', 'berth',
        'eta', 'etb', 'etd', 'ata', 'atb', 'atd', 'status', 'remarks', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return ['eta' => 'datetime', 'etb' => 'datetime', 'etd' => 'datetime', 'ata' => 'datetime', 'atb' => 'datetime', 'atd' => 'datetime',
            'sequence' => 'integer', 'lock_version' => 'integer'];
    }

    /** @return BelongsTo<Voyage, $this> */
    public function voyage(): BelongsTo
    {
        return $this->belongsTo(Voyage::class);
    }

    /** @return BelongsTo<Port, $this> */
    public function port(): BelongsTo
    {
        return $this->belongsTo(Port::class);
    }

    /** @return BelongsTo<OffshoreLocation, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(OffshoreLocation::class, 'offshore_location_id');
    }

    /** @return BelongsTo<Company, $this> */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'agent_company_id');
    }

    /** IANA timezone of the call's port/location (local entry & display, G-06). */
    public function timezone(): string
    {
        return (string) ($this->port?->getAttribute('timezone') ?? $this->location?->getAttribute('timezone') ?? 'UTC');
    }

    public function label(): string
    {
        return $this->port ? "{$this->port->getAttribute('name')} ({$this->port->getAttribute('unlocode')})" : (string) ($this->location?->getAttribute('name') ?? '—');
    }
}
