<?php

namespace App\Models;

use App\Enums\VoyageRevenueStatus;
use App\Models\Concerns\HasAuditLog;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class VoyageRevenue extends Model
{
    use HasAuditLog, HasFactory, SoftDeletes;

    protected $attributes = ['status' => 'draft'];

    protected $fillable = [
        'voyage_id',
        'contract_id',
        'offshore_activity_id',
        'laytime_calculation_id',
        'revenue_category_id',
        'description',
        'is_estimate',
        'quantity',
        'rate',
        'currency',
        'fx_rate',
        'amount',
        'base_amount',
        'commission_pct_total',
        'commission_amount',
        'status',
        'service_period_from',
        'service_period_to',
        'remarks',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_estimate' => 'boolean',
        'quantity' => 'string',
        'rate' => 'string',
        'fx_rate' => 'string',
        'amount' => 'string',
        'base_amount' => 'string',
        'commission_pct_total' => 'string',
        'commission_amount' => 'string',
        'status' => VoyageRevenueStatus::class,
        'service_period_from' => 'date',
        'service_period_to' => 'date',
    ];

    public function voyage(): BelongsTo
    {
        return $this->belongsTo(Voyage::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function offshoreActivity(): BelongsTo
    {
        return $this->belongsTo(OffshoreActivity::class);
    }

    public function laytimeCalculation(): BelongsTo
    {
        return $this->belongsTo(LaytimeCalculation::class);
    }

    public function revenueCategory(): BelongsTo
    {
        return $this->belongsTo(RevenueCategory::class);
    }

    /** Alias used by VoyageRevenueService/VoyageRevenueResource. */
    public function category(): BelongsTo
    {
        return $this->belongsTo(RevenueCategory::class, 'revenue_category_id');
    }

    public function invoiceLine(): HasOne
    {
        return $this->hasOne(InvoiceLine::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function isEditable(): bool
    {
        return $this->status->canEdit();
    }
}
