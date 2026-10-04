<?php

namespace App\Models;

use App\Models\Concerns\HasAuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $offer_number
 * @property int $enquiry_id
 * @property int $vessel_id
 * @property string $status
 * @property Carbon|null $created_at
 */
class Offer extends Model
{
    use HasAuditLog, SoftDeletes;

    protected $attributes = ['status' => 'open'];

    protected $fillable = ['offer_number', 'enquiry_id', 'vessel_id', 'counterparty_company_id', 'status', 'created_by'];

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

    /** @return BelongsTo<Company, $this> */
    public function counterparty(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'counterparty_company_id');
    }

    /** @return HasMany<OfferRevision, $this> */
    public function revisions(): HasMany
    {
        return $this->hasMany(OfferRevision::class)->orderBy('revision_no');
    }
}
