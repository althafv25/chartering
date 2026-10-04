<?php

namespace App\Models;

use App\Enums\VoyageExpenseStatus;
use App\Models\Concerns\GuardsVoyageFinancials;
use App\Models\Concerns\HasAuditLog;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class VoyageExpense extends Model
{
    use GuardsVoyageFinancials, HasAuditLog, HasFactory, SoftDeletes;

    protected $attributes = ['status' => 'draft'];

    protected $fillable = [
        'voyage_id',
        'contract_id',
        'expense_category_id',
        'source_type',
        'source_id',
        'description',
        'is_estimate',
        'quantity',
        'rate',
        'currency',
        'fx_rate',
        'amount',
        'base_amount',
        'supplier_company_id',
        'status',
        'incurred_at',
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
        'status' => VoyageExpenseStatus::class,
        'incurred_at' => 'datetime',
    ];

    public function voyage(): BelongsTo
    {
        return $this->belongsTo(Voyage::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function expenseCategory(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class);
    }

    /** Alias used by VoyageExpenseService/VoyageExpenseResource. */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function supplierCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'supplier_company_id');
    }

    /** Alias used by VoyageExpenseService/VoyageExpenseResource. */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'supplier_company_id');
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
