<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Models\Concerns\HasAuditLog;
use App\Models\Concerns\HasLockVersion;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Invoice extends Model
{
    use HasAuditLog, HasFactory, HasLockVersion, SoftDeletes;

    public const STATUSES = ['draft', 'submitted', 'approved', 'issued', 'partially_paid', 'paid', 'overdue', 'cancelled'];

    public const TYPES = ['freight', 'hire', 'offshore_service', 'demurrage', 'other', 'credit_note'];

    protected $attributes = [
        'status' => 'draft',
        'subtotal' => '0.00',
        'tax_amount' => '0.00',
        'total' => '0.00',
        'base_total' => '0.00',
        'amount_paid' => '0.00',
        'lock_version' => 0,
    ];

    protected $fillable = [
        'invoice_number',
        'invoice_type',
        'customer_company_id',
        'billing_snapshot',
        'contract_id',
        'voyage_id',
        'issue_date',
        'due_date',
        'currency',
        'fx_rate',
        'subtotal',
        'tax_amount',
        'total',
        'base_total',
        'amount_paid',
        'status',
        'cancelled_reason',
        'credit_note_for_id',
        'pdf_document_id',
        'remarks',
        'submitted_by',
        'submitted_at',
        'approved_by',
        'approved_at',
        'issued_by',
        'issued_at',
        'lock_version',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'invoice_type' => InvoiceType::class,
        'billing_snapshot' => 'array',
        'issue_date' => 'date',
        'due_date' => 'date',
        'fx_rate' => 'string',
        'subtotal' => 'string',
        'tax_amount' => 'string',
        'total' => 'string',
        'base_total' => 'string',
        'amount_paid' => 'string',
        'balance' => 'string',
        'status' => InvoiceStatus::class,
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'issued_at' => 'datetime',
        'lock_version' => 'integer',
    ];

    public function getBalanceAttribute(): string
    {
        return bcsub($this->total, $this->amount_paid, 2);
    }

    public function isEditable(): bool
    {
        return $this->status === InvoiceStatus::Draft;
    }

    /** @return BelongsTo<Company, $this> */
    public function customerCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'customer_company_id');
    }

    /**
     * Alias used by controllers/resources/eager-loads.
     *
     * @return BelongsTo<Company, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->customerCompany();
    }

    /** @return BelongsTo<Contract, $this> */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    /** @return BelongsTo<Voyage, $this> */
    public function voyage(): BelongsTo
    {
        return $this->belongsTo(Voyage::class);
    }

    /** @return BelongsTo<Invoice, $this> */
    public function creditNoteFor(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'credit_note_for_id');
    }

    /** @return HasMany<Invoice, $this> */
    public function creditNotes(): HasMany
    {
        return $this->hasMany(Invoice::class, 'credit_note_for_id');
    }

    /** @return BelongsTo<Document, $this> */
    public function pdfDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'pdf_document_id');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    /** @return HasMany<InvoiceLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class)->orderBy('sequence');
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
