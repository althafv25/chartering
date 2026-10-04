<?php

namespace App\Models;

use App\Enums\PaymentDirection;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Concerns\HasAuditLog;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Payment extends Model
{
    use HasAuditLog, HasFactory, SoftDeletes;

    public const METHODS = ['wire', 'check', 'credit_card', 'cash', 'other'];

    protected $attributes = [
        'status' => 'recorded',
        'base_amount' => '0.00',
        'unallocated_amount' => '0.00',
    ];

    protected $fillable = [
        'payment_number',
        'direction',
        'company_id',
        'payment_date',
        'amount',
        'currency',
        'fx_rate',
        'base_amount',
        'unallocated_amount',
        'bank_account_ref',
        'bank_reference',
        'method',
        'remarks',
        'status',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'direction' => PaymentDirection::class,
        'payment_date' => 'date',
        'amount' => 'string',
        'fx_rate' => 'string',
        'base_amount' => 'string',
        'unallocated_amount' => 'string',
        'method' => PaymentMethod::class,
        'status' => PaymentStatus::class,
    ];

    public function isReversed(): bool
    {
        return $this->status === PaymentStatus::Reversed;
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return HasMany<PaymentAllocation, $this> */
    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
