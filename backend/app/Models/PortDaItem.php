<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $port_da_id
 * @property int $da_cost_category_id
 * @property string|null $description
 * @property string|null $estimated_amount
 * @property string|null $actual_amount
 * @property string|null $variance_amount
 * @property string|null $remarks
 * @property int $sequence
 */
class PortDaItem extends Model
{
    protected $fillable = ['port_da_id', 'da_cost_category_id', 'description', 'estimated_amount', 'actual_amount', 'variance_amount', 'remarks', 'sequence'];

    protected $attributes = ['sequence' => 0];

    /** @return BelongsTo<PortDa, $this> */
    public function portDa(): BelongsTo
    {
        return $this->belongsTo(PortDa::class);
    }

    /** @return BelongsTo<DaCostCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(DaCostCategory::class, 'da_cost_category_id');
    }
}
