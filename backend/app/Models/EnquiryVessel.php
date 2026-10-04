<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property string $shortlist_status */
class EnquiryVessel extends Model
{
    protected $attributes = ['shortlist_status' => 'candidate'];

    protected $fillable = ['enquiry_id', 'vessel_id', 'shortlist_status', 'notes'];

    /** @return BelongsTo<Vessel, $this> */
    public function vessel(): BelongsTo
    {
        return $this->belongsTo(Vessel::class);
    }
}
