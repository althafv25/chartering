<?php

namespace App\Models;

use App\Enums\PayableStatus;
use App\Models\Concerns\HasAuditLog;
use App\Models\Concerns\HasLockVersion;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Payable extends Model
{
    use HasAuditLog, HasFactory, HasLockVersion, SoftDeletes;

    public const STATUSES = ['draft', 'approved', 'partially_paid', 'paid', 'cancelled'];

    protected $attributes = [
        'status' => 'draft',
        'subtotal' => '0.00',
        'tax' => '0.00',
        'total' => '0.00',
        'base_total' => '0.00',
        'amount_paid' => '0.00',
        'lock_version' => 0,
    ];

    protected $fillable = [
        'payable_number',
        'supplier_company_id',
        'supplier_invoice_ref',
        'voyage_id',
        'issue_date',
        'due_date',
        'currency',
        'fx_rate',
        'subtotal',
        'tax',
        'total',
        'base_total',
        'amount_paid',
        'status',
        'remarks',
        'approved_by',
        'approved_at',
        'lock_version',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'issue_date' => 'date',
        'due_date' => 'date',
        'fx_rate' => 'string',
        'subtotal' => 'string',
        'tax' => 'string',
        'total' => 'string',
        'base_total' => 'string',
        'amount_paid' => 'string',
        'balance' => 'string',
        'status' => PayableStatus::class,
        'approved_at' => 'datetime',
        'lock_version' => 'integer',
    ];

    public function getBalanceAttribute(): string
    {
        return bcsub($this->total, $this->amount_paid, 2);
    }

    public function isEditable(): bool
    {
        return $this->status === PayableStatus::Draft;
    }

    /** @return BelongsTo<Company, $this> */
    public function supplierCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'supplier_company_id');
    }

    /**
     * Alias used by service/resource/controller.
     *
     * @return BelongsTo<Company, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->supplierCompany();
    }

    /** @return BelongsTo<Voyage, $this> */
    public function voyage(): BelongsTo
    {
        return $this->belongsTo(Voyage::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return HasMany<PaymentAllocation, $this> */
    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function expenses(): MorphMany
    {
        return $this->morphMany(VoyageExpense::class, 'source');
    }

    /** Alias used by PayableService/PayableResource. */
    public function voyageExpenses(): MorphMany
    {
        return $this->expenses();
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
