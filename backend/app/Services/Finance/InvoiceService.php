<?php

namespace App\Services\Finance;

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\Permission;
use App\Enums\VoyageRevenueStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\Currency;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Payment;
use App\Models\TaxCode;
use App\Models\User;
use App\Models\Voyage;
use App\Models\VoyageRevenue;
use App\Services\Chartering\ApprovalGuard;
use App\Services\ExchangeRateService;
use App\Services\SequenceService;
use App\Support\Decimal;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Accounts receivable invoices.
 *
 * Lifecycle (INV-01): draft → (submitted → approved) → issued → partially_paid → paid;
 * issued+overdue is set by a daily job; draft/approved → cancelled; issued → cancelled
 * only via a credit note. INV-02: a revenue line appears on at most one active invoice.
 * INV-03: the number is assigned at Issue from a gap-free yearly sequence under a row lock.
 * INV-04 / F2 / F3: totals are calculated server-side; F1 FX snapshot at issue (and at
 * creation for drafts, refreshed on every recalculation while still a draft).
 *
 * F2: line amount = round(qty × rate, d); line tax = round(amount × tax% / 100, d)
 * F3: subtotal = Σ amount; tax = Σ tax; total = subtotal + tax; balance = total − Σ allocations
 */
class InvoiceService
{
    private const EDITABLE_FIELDS = [
        'invoice_type', 'customer_company_id', 'contract_id', 'voyage_id', 'issue_date', 'due_date', 'currency', 'remarks',
    ];

    public function __construct(
        private readonly ExchangeRateService $fx,
        private readonly SequenceService $sequences,
        private readonly ApprovalGuard $approvals,
    ) {}

    /** @return LengthAwarePaginator<int, Invoice> */
    public function paginate(User $actor, array $filters, int $perPage): LengthAwarePaginator
    {
        $this->authorize($actor, Permission::InvoicesView);

        $query = Invoice::query()->with(['customer', 'contract', 'voyage', 'creditNoteFor']);
        $this->applyFilters($query, $filters);

        return $query->latest('id')->paginate($perPage);
    }

    public function find(User $actor, int $id): Invoice
    {
        $this->authorize($actor, Permission::InvoicesView);

        return Invoice::query()->with(['customer', 'contract', 'voyage', 'lines.taxCode', 'lines.voyageRevenue', 'creditNoteFor', 'allocations.payment'])->findOrFail($id);
    }

    /** @param array<string, mixed> $data */
    public function create(array $data, User $actor): Invoice
    {
        $this->authorize($actor, Permission::InvoicesCreate);

        return DB::transaction(function () use ($data, $actor) {
            $customer = Company::query()->findOrFail((int) $data['customer_company_id']);
            $invoice = new Invoice(['created_by' => $actor->id, 'updated_by' => $actor->id]);
            $invoice->fill(Arr::only($data, self::EDITABLE_FIELDS));
            $invoice->billing_snapshot = $this->billingSnapshot($customer);
            $this->snapshotFx($invoice);
            $invoice->fill(['subtotal' => '0.00', 'tax_amount' => '0.00', 'total' => '0.00', 'base_total' => '0.00']);
            $invoice->save();

            if (! empty($data['revenue_ids'])) {
                $this->attachRevenueLines($invoice, (array) $data['revenue_ids'], $actor);
            }
            $this->recalculateTotals($invoice);

            return $invoice->refresh();
        });
    }

    /** @param array<string, mixed> $data */
    public function update(Invoice $invoice, array $data, User $actor): Invoice
    {
        $this->authorize($actor, Permission::InvoicesUpdate);

        return DB::transaction(function () use ($invoice, $data, $actor) {
            $locked = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            $locked->assertLockVersion(isset($data['lock_version']) ? (int) $data['lock_version'] : null);
            if (! $locked->isEditable()) {
                throw new BusinessRuleException("A {$locked->status->value} invoice cannot be edited.", 'invoice_read_only');
            }
            $locked->fill([...Arr::only($data, self::EDITABLE_FIELDS), 'updated_by' => $actor->id]);
            if (array_key_exists('customer_company_id', $data)) {
                $locked->billing_snapshot = $this->billingSnapshot(Company::query()->findOrFail((int) $data['customer_company_id']));
            }
            $this->snapshotFx($locked);
            $locked->save();
            $this->recalculateTotals($locked);

            return $locked->refresh();
        });
    }

    /**
     * Replaces the invoice lines wholesale from the given rows. Each row may
     * reference a voyage_revenue_id (billing a confirmed revenue line, locked
     * to prevent double-invoicing — INV-02) or be a free-text/manual line.
     *
     * @param  list<array<string, mixed>>  $lines
     */
    public function saveLines(Invoice $invoice, array $lines, User $actor): Invoice
    {
        $this->authorize($actor, Permission::InvoicesUpdate);

        return DB::transaction(function () use ($invoice, $lines, $actor) {
            $locked = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            if (! $locked->isEditable()) {
                throw new BusinessRuleException("A {$locked->status->value} invoice cannot be edited.", 'invoice_read_only');
            }

            $this->releaseRevenueLines($locked);
            $locked->lines()->delete();
            $seq = 0;
            foreach ($lines as $row) {
                $this->addLine($locked, $row, ++$seq, $actor);
            }
            $locked->setAttribute('updated_by', $actor->id)->save();
            $this->recalculateTotals($locked);

            return $locked->refresh();
        });
    }

    /** Convenience: bill a set of confirmed voyage revenues directly onto the invoice. */
    public function attachRevenueLines(Invoice $invoice, array $revenueIds, User $actor): Invoice
    {
        $this->authorize($actor, Permission::InvoicesUpdate);

        return DB::transaction(function () use ($invoice, $revenueIds, $actor) {
            $locked = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            if (! $locked->isEditable()) {
                throw new BusinessRuleException("A {$locked->status->value} invoice cannot be edited.", 'invoice_read_only');
            }
            $seq = (int) $locked->lines()->max('sequence');
            $revenues = VoyageRevenue::query()->whereIn('id', $revenueIds)->lockForUpdate()->get();
            foreach ($revenues as $revenue) {
                if ($revenue->status !== VoyageRevenueStatus::Confirmed) {
                    throw new BusinessRuleException("Revenue line #{$revenue->id} must be confirmed before invoicing.", 'revenue_not_confirmed');
                }
                if ($revenue->invoiceLine()->exists()) {
                    throw new BusinessRuleException("Revenue line #{$revenue->id} is already invoiced.", 'revenue_invoiced');
                }
                $this->addLine($locked, [
                    'voyage_revenue_id' => $revenue->id,
                    'description' => $revenue->description,
                    'quantity' => $revenue->quantity,
                    'rate' => $revenue->rate,
                    'amount' => $revenue->amount,
                ], ++$seq, $actor);
                $revenue->fill(['status' => VoyageRevenueStatus::Invoiced])->save();
            }
            $locked->setAttribute('updated_by', $actor->id)->save();
            $this->recalculateTotals($locked);

            return $locked->refresh();
        });
    }

    public function submit(Invoice $invoice, User $actor): Invoice
    {
        $this->authorize($actor, Permission::InvoicesSubmit);

        return $this->move($invoice, [InvoiceStatus::Draft], InvoiceStatus::Submitted, $actor, function (Invoice $i) use ($actor) {
            if ($i->lines()->count() === 0) {
                throw new BusinessRuleException('An invoice needs at least one line before it can be submitted.', 'invoice_empty');
            }
            $i->fill(['submitted_by' => $actor->id, 'submitted_at' => now()]);
        });
    }

    public function approve(Invoice $invoice, User $actor): Invoice
    {
        $this->authorize($actor, Permission::InvoicesApprove);

        return $this->move($invoice, [InvoiceStatus::Submitted], InvoiceStatus::Approved, $actor, function (Invoice $i) use ($actor) {
            $this->approvals->assertNotSelfDecision($i->submitted_by, $actor);
            $i->fill(['approved_by' => $actor->id, 'approved_at' => now()]);
        });
    }

    public function reject(Invoice $invoice, User $actor, string $reason): Invoice
    {
        $this->authorize($actor, Permission::InvoicesApprove);

        return $this->move($invoice, [InvoiceStatus::Submitted], InvoiceStatus::Draft, $actor, function (Invoice $i) use ($reason) {
            $i->fill(['submitted_by' => null, 'submitted_at' => null, 'remarks' => trim(($i->remarks ? $i->remarks."\n" : '')."Rejected: {$reason}")]);
        });
    }

    /** INV-03: assigns the gap-free yearly number under a row lock and snapshots FX one last time. */
    public function issue(Invoice $invoice, User $actor): Invoice
    {
        $this->authorize($actor, Permission::InvoicesIssue);

        return DB::transaction(function () use ($invoice, $actor) {
            $locked = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            if ($locked->voyage_id) {
                $voyage = Voyage::query()->lockForUpdate()->findOrFail($locked->voyage_id);
                if (! $voyage->isOperationallyOpen()) {
                    throw new BusinessRuleException('Reopen the voyage before issuing its invoice.', 'voyage_read_only');
                }
            }
            $approvalRequired = (bool) config('offshore.approvals.invoices_require_approval', false);
            $allowedFrom = $approvalRequired ? [InvoiceStatus::Approved] : [InvoiceStatus::Draft, InvoiceStatus::Approved];
            if (! in_array($locked->status, $allowedFrom, true)) {
                throw new BusinessRuleException("Invoice cannot be issued from status {$locked->status->value}.", 'invalid_status_transition');
            }
            if ($locked->lines()->count() === 0) {
                throw new BusinessRuleException('An invoice needs at least one line before it can be issued.', 'invoice_empty');
            }
            $this->snapshotFx($locked);
            $locked->invoice_number = $this->sequences->next('invoice', 'INV-'.now()->format('Y').'-');
            $locked->fill(['status' => InvoiceStatus::Issued, 'issued_by' => $actor->id, 'issued_at' => now(), 'updated_by' => $actor->id])->save();
            $this->recalculateTotals($locked);

            return $locked->refresh();
        });
    }

    /** INV-01: an issued invoice can only be cancelled through a credit note. */
    public function cancel(Invoice $invoice, User $actor, string $reason): Invoice
    {
        $this->authorize($actor, Permission::InvoicesCancel);

        return DB::transaction(function () use ($invoice, $actor, $reason) {
            $locked = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            if (! in_array($locked->status, [InvoiceStatus::Draft, InvoiceStatus::Approved], true)) {
                throw new BusinessRuleException("A {$locked->status->value} invoice cannot be cancelled directly. Issue a credit note instead.", 'invoice_requires_credit_note');
            }
            $this->releaseRevenueLines($locked);
            $locked->fill(['status' => InvoiceStatus::Cancelled, 'cancelled_reason' => $reason, 'updated_by' => $actor->id])->save();

            return $locked;
        });
    }

    /** Issues a credit note against an issued invoice, releasing its revenue lines and reversing its totals. */
    public function creditNote(Invoice $invoice, User $actor, string $reason): Invoice
    {
        $this->authorize($actor, Permission::InvoicesCreate);

        return DB::transaction(function () use ($invoice, $actor, $reason) {
            $original = Invoice::query()->lockForUpdate()->with('lines.taxCode')->findOrFail($invoice->id);
            if ($original->invoice_type === InvoiceType::CREDIT_NOTE || ! in_array($original->status, [InvoiceStatus::Issued, InvoiceStatus::PartiallyPaid, InvoiceStatus::Paid, InvoiceStatus::Overdue], true)) {
                throw new BusinessRuleException('Only an issued invoice can be credited.', 'invalid_status_transition');
            }

            // A full credit releases receipts for reallocation or reversal; never strand
            // allocations on a cancelled invoice. PaymentService records the release audit.
            foreach ($original->allocations()->orderBy('payment_id')->get() as $allocation) {
                app(PaymentService::class)->unallocate(Payment::query()->findOrFail($allocation->payment_id), $allocation, $actor);
            }
            $original->refresh();

            $credit = new Invoice([
                'invoice_type' => 'credit_note',
                'customer_company_id' => $original->customer_company_id,
                'billing_snapshot' => $original->billing_snapshot,
                'contract_id' => $original->contract_id,
                'voyage_id' => $original->voyage_id,
                'issue_date' => now()->toDateString(),
                'due_date' => now()->toDateString(),
                'currency' => $original->currency,
                'credit_note_for_id' => $original->id,
                'remarks' => $reason,
                'subtotal' => '0.00',
                'tax_amount' => '0.00',
                'total' => '0.00',
                'base_total' => '0.00',
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);
            $credit->fx_rate = $original->fx_rate;
            $credit->save();

            $seq = 0;
            foreach ($original->lines as $line) {
                $credit->lines()->create([
                    'sequence' => ++$seq,
                    'description' => "Credit: {$line->description}",
                    'quantity' => $line->quantity,
                    'unit' => $line->unit,
                    'rate' => $line->rate,
                    'amount' => Decimal::round(Decimal::mul((string) $line->amount, '-1'), 2),
                    'tax_code_id' => $line->tax_code_id,
                    'tax_rate_pct' => $line->tax_rate_pct,
                    'tax_amount' => Decimal::round(Decimal::mul((string) $line->tax_amount, '-1'), 2),
                    'line_total' => Decimal::round(Decimal::mul((string) $line->line_total, '-1'), 2),
                ]);
            }
            $this->recalculateTotals($credit);
            $credit->invoice_number = $this->sequences->next('invoice', 'INV-'.now()->format('Y').'-');
            $credit->fill(['status' => InvoiceStatus::Issued, 'issued_by' => $actor->id, 'issued_at' => now()])->save();

            $this->releaseRevenueLines($original);
            $original->fill(['status' => InvoiceStatus::Cancelled, 'cancelled_reason' => "Credited by {$credit->invoice_number}: {$reason}", 'updated_by' => $actor->id])->save();

            return $credit->refresh();
        });
    }

    public function delete(Invoice $invoice, User $actor): void
    {
        $this->authorize($actor, Permission::InvoicesUpdate);

        DB::transaction(function () use ($invoice) {
            $locked = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            if (! $locked->isEditable()) {
                throw new BusinessRuleException("A {$locked->status->value} invoice cannot be deleted.", 'invoice_read_only');
            }
            $this->releaseRevenueLines($locked);
            $locked->delete();
        });
    }

    /**
     * Renders a placeholder PDF (resources/views/finance/invoice-pdf.blade.php),
     * stores it on the documents disk and links it as invoice.pdf_document_id.
     */
    public function generatePdf(Invoice $invoice, User $actor): Document
    {
        $this->authorize($actor, Permission::InvoicesPrint);

        return DB::transaction(function () use ($invoice, $actor) {
            $locked = Invoice::query()->lockForUpdate()->with(['lines.taxCode', 'customer'])->findOrFail($invoice->id);

            $pdf = Pdf::loadView('finance.invoice-pdf', [
                'invoice' => $locked,
                'company' => ['name' => (string) config('offshore.company.name', config('app.name'))],
            ]);
            $binary = $pdf->output();

            $disk = (string) config('offshore.documents.disk');
            $directory = sprintf('invoices/%d/%s', $locked->id, now()->format('Y/m'));
            $filename = Str::uuid().'.pdf';
            $path = $directory.'/'.$filename;
            Storage::disk($disk)->put($path, $binary);

            $document = Document::query()->create([
                'documentable_type' => 'invoices',
                'documentable_id' => $locked->id,
                'document_type_id' => DocumentType::query()->where('code', 'invoice')->value('id'),
                'title' => $locked->invoice_number ?? "Invoice draft #{$locked->id}",
                'document_number' => $locked->invoice_number,
                'disk' => $disk,
                'path' => $path,
                'original_filename' => ($locked->invoice_number ?? "invoice-{$locked->id}").'.pdf',
                'mime_type' => 'application/pdf',
                'size_bytes' => strlen($binary),
                'sha256' => hash('sha256', $binary),
                'uploaded_by' => $actor->id,
            ]);

            $locked->fill(['pdf_document_id' => $document->id, 'updated_by' => $actor->id])->save();

            return $document;
        });
    }

    /**
     * Called by PaymentService inside its own transaction/lock after allocating or
     * unallocating funds. Recomputes amount_paid from the given delta (positive to
     * allocate, negative to unallocate) and transitions status per PAY-02.
     */
    public function updatePaidAmount(Invoice $invoice, string $deltaInInvoiceCurrency): Invoice
    {
        $invoice->amount_paid = Decimal::round(Decimal::add((string) $invoice->amount_paid, $deltaInInvoiceCurrency), 2);
        $balance = Decimal::round(Decimal::sub((string) $invoice->total, (string) $invoice->amount_paid), 2);
        $invoice->status = Decimal::isZero($balance)
            ? InvoiceStatus::Paid
            : (Decimal::cmp($invoice->amount_paid, '0') > 0 ? InvoiceStatus::PartiallyPaid : InvoiceStatus::Issued);
        $invoice->save();

        return $invoice;
    }

    /** @param array<string, mixed> $row */
    private function addLine(Invoice $invoice, array $row, int $sequence, User $actor): InvoiceLine
    {
        if (! empty($row['voyage_revenue_id'])) {
            $revenue = VoyageRevenue::query()->lockForUpdate()->findOrFail($row['voyage_revenue_id']);
            if ($revenue->status !== VoyageRevenueStatus::Confirmed || $revenue->invoiceLine()->exists()) {
                throw new BusinessRuleException('Only confirmed, unbilled revenue can be attached to an invoice.', 'revenue_invoiced');
            }
            if ($revenue->currency !== $invoice->currency) {
                throw new BusinessRuleException('Revenue and invoice currencies must match.', 'currency_mismatch');
            }
            $revenue->fill(['status' => VoyageRevenueStatus::Invoiced])->save();
        }
        $decimals = Currency::query()->where('code', $invoice->currency)->value('decimals') ?? 2;
        $qty = isset($row['quantity']) ? Decimal::round((string) $row['quantity'], 4) : null;
        $rate = isset($row['rate']) ? Decimal::round((string) $row['rate'], 4) : '0';
        $amount = isset($row['amount'])
            ? Decimal::round((string) $row['amount'], $decimals)
            : ($qty !== null ? Decimal::round(Decimal::mul($qty, $rate), $decimals) : '0'); // F2

        $taxCode = isset($row['tax_code_id']) && $row['tax_code_id'] ? TaxCode::query()->find((int) $row['tax_code_id']) : null;
        $taxRatePct = $taxCode ? Decimal::round((string) $taxCode->rate_pct, 4) : '0';
        $taxAmount = Decimal::round(Decimal::mul($amount, Decimal::div($taxRatePct, '100')), $decimals); // F2
        $lineTotal = Decimal::round(Decimal::add($amount, $taxAmount), $decimals);

        return $invoice->lines()->create([
            'sequence' => $sequence,
            'voyage_revenue_id' => $row['voyage_revenue_id'] ?? null,
            'description' => (string) $row['description'],
            'quantity' => $qty,
            'unit' => $row['unit'] ?? null,
            'rate' => $rate,
            'amount' => $amount,
            'tax_code_id' => $taxCode?->id,
            'tax_rate_pct' => $taxRatePct,
            'tax_amount' => $taxAmount,
            'line_total' => $lineTotal,
        ]);
    }

    private function releaseRevenueLines(Invoice $invoice): void
    {
        foreach ($invoice->lines()->whereNull('released_at')->whereNotNull('voyage_revenue_id')->get() as $line) {
            $revenue = VoyageRevenue::query()->lockForUpdate()->findOrFail($line->voyage_revenue_id);
            $line->fill(['released_at' => now()])->save();
            $revenue->fill(['status' => VoyageRevenueStatus::Confirmed])->save();
        }
    }

    /** F3: subtotal = Σ amount; tax = Σ tax; total = subtotal + tax. base_total via F1. */
    private function recalculateTotals(Invoice $invoice): void
    {
        $decimals = Currency::query()->where('code', $invoice->currency)->value('decimals') ?? 2;
        $lines = $invoice->lines()->get();
        $subtotal = '0';
        $tax = '0';
        foreach ($lines as $line) {
            $subtotal = Decimal::add($subtotal, (string) $line->amount);
            $tax = Decimal::add($tax, (string) $line->tax_amount);
        }
        $subtotal = Decimal::round($subtotal, $decimals);
        $tax = Decimal::round($tax, $decimals);
        $total = Decimal::round(Decimal::add($subtotal, $tax), $decimals);
        $baseTotal = Decimal::round(Decimal::mul($total, (string) $invoice->fx_rate), 2); // F1

        $invoice->fill(['subtotal' => $subtotal, 'tax_amount' => $tax, 'total' => $total, 'base_total' => $baseTotal])->save();
    }

    /** Snapshots FX to base currency; only re-snapshots while the invoice is still editable. */
    private function snapshotFx(Invoice $invoice): void
    {
        $base = (string) config('offshore.base_currency');
        $fxInfo = $this->fx->resolve($invoice->currency, $base, $invoice->issue_date ?? now());
        $invoice->fx_rate = $fxInfo['rate'];
    }

    /** @return array<string, mixed> */
    private function billingSnapshot(Company $company): array
    {
        $address = trim(implode(', ', array_filter([$company->address_line1, $company->address_line2, $company->city, $company->postal_code, $company->country])));

        return [
            'name' => $company->legal_name,
            'address' => $address,
            'tax_no' => $company->tax_number,
            'country' => $company->country,
        ];
    }

    /** @param  list<InvoiceStatus>  $from */
    private function move(Invoice $invoice, array $from, InvoiceStatus $to, User $actor, callable $mutate): Invoice
    {
        return DB::transaction(function () use ($invoice, $from, $to, $actor, $mutate) {
            $locked = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            if (! in_array($locked->status, $from, true)) {
                throw new BusinessRuleException("Invoice cannot move from {$locked->status->value} to {$to->value}.", 'invalid_status_transition');
            }
            $mutate($locked);
            $locked->fill(['status' => $to, 'updated_by' => $actor->id])->save();

            return $locked->refresh();
        });
    }

    /** @param array<string, mixed> $filters */
    private function applyFilters(Builder $query, array $filters): void
    {
        if (! empty($filters['customer_company_id'])) {
            $query->where('customer_company_id', (int) $filters['customer_company_id']);
        }
        if (! empty($filters['voyage_id'])) {
            $query->where('voyage_id', (int) $filters['voyage_id']);
        }
        if (! empty($filters['status'])) {
            $query->where('status', (string) $filters['status']);
        }
        if (! empty($filters['invoice_type'])) {
            $query->where('invoice_type', (string) $filters['invoice_type']);
        }
    }

    private function authorize(User $actor, Permission $permission): void
    {
        if (! $actor->can($permission->value)) {
            throw new AuthorizationException('You do not have permission to perform this action.');
        }
    }
}
