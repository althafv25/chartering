<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $sequence
 * @property int|null $port_id
 * @property int|null $offshore_location_id
 * @property string $purpose
 */
class EnquiryPort extends Model
{
    public const PURPOSES = ['load', 'discharge', 'bunker', 'supply_base', 'offshore_ops', 'delivery', 'redelivery', 'other'];

    protected $fillable = ['enquiry_id', 'sequence', 'port_id', 'offshore_location_id', 'purpose', 'notes'];

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

    /** @return array{type:string,id:int,label:string} */
    public function point(): array
    {
        return $this->port_id
            ? ['type' => 'port', 'id' => (int) $this->port_id, 'label' => $this->port?->label() ?? "Port #{$this->port_id}"]
            : ['type' => 'location', 'id' => (int) $this->offshore_location_id, 'label' => $this->offshoreLocation?->name ?? "Location #{$this->offshore_location_id}"];
    }
}
