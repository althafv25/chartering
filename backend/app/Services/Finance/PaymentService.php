<?php

namespace App\Services\Finance;

use App\Enums\InvoiceStatus;
use App\Enums\PayableStatus;
use App\Enums\PaymentDirection;
use App\Enums\PaymentStatus;
use App\Enums\Permission;
use App\Exceptions\BusinessRuleException;
use App\Models\Invoice;
use App\Models\Payable;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\User;
use App\Services\ExchangeRateService;
use App\Services\SequenceService;
use App\Support\Decimal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Payments received from customers or paid to suppliers, and their
 * allocation to invoices / payables.
 *
 * PAY-01 Σ allocations ≤ payment amount. Allocation to an invoice/payable ≤ its balance.
 * PAY-02 Status after allocation: balance = 0 → Paid; 0 < paid < total → Partially Paid.
 * PAY-03 Duplicate guard: same company + bank_reference + amount + date → warning
 *        (surfaced to the caller as a 409 unless `confirm_duplicate` is set).
 * PAY-04 Cross-currency allocation: fx_difference_base is the realised FX gain/loss.
 *
 * F4: invoice_ccy_amount = round(allocated × fx(pay→inv), d);
 *     fx_difference_base = allocated_base_at_payment_rate − invoice_ccy_amount_base_at_invoice_rate
 */
class PaymentService
{
    private const EDITABLE_FIELDS = [
        'direction', 'company_id', 'payment_date', 'amount', 'currency', 'bank_account_ref', 'bank_reference', 'method', 'remarks',
    ];

    public function __construct(
        private readonly ExchangeRateService $fx,
        private readonly SequenceService $sequences,
        private readonly InvoiceService $invoices,
        private readonly PayableService $payables,
    ) {}

    /** @return LengthAwarePaginator<int, Payment> */
    public function paginate(User $actor, array $filters, int $perPage): LengthAwarePaginator
    {
        $this->authorize($actor, Permission::PaymentsView);

        $query = Payment::query()->with(['company']);
        $this->applyFilters($query, $filters);

        return $query->latest('id')->paginate($perPage);
    }

    public function find(User $actor, int $id): Payment
    {
        $this->authorize($actor, Permission::PaymentsView);

        return Payment::query()->with(['company', 'allocations.invoice', 'allocations.payable'])->findOrFail($id);
    }

    /**
     * Records a payment. Duplicate detection (PAY-03) requires an explicit
     * `confirm_duplicate = true` to proceed once a likely duplicate is found.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $actor): Payment
    {
        $this->authorize($actor, Permission::PaymentsCreate);

        return DB::transaction(function () use ($data, $actor) {
            $this->guardDuplicate($data);

            $payment = new Payment(['created_by' => $actor->id, 'updated_by' => $actor->id]);
            $payment->fill(Arr::only($data, self::EDITABLE_FIELDS));
            $payment->payment_number = $this->sequences->next('payment', 'PAY-'.now()->format('Y').'-');
            $this->snapshotFx($payment);
            $payment->unallocated_amount = $payment->amount;
            $payment->status = PaymentStatus::Recorded;
            $payment->save();

            return $payment;
        });
    }

    /**
     * Allocates (part of) a payment to one or more invoices/payables.
     * PAY-01: total allocated this call + already-allocated ≤ payment amount;
     * each allocation ≤ the target's current balance.
     *
     * @param  list<array{invoice_id?: int, payable_id?: int, amount: string|float|int}>  $allocations
     * @return list<PaymentAllocation>
     */
    public function allocate(Payment $payment, array $allocations, User $actor): array
    {
        $this->authorize($actor, Permission::PaymentsAllocate);

        return DB::transaction(function () use ($payment, $allocations, $actor) {
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            if ($locked->isReversed()) {
                throw new BusinessRuleException('A reversed payment cannot be allocated.', 'payment_reversed');
            }

            $created = [];
            $totalThisCall = '0';
            foreach ($allocations as $row) {
                if (empty($row['invoice_id']) === empty($row['payable_id'])) {
                    throw new BusinessRuleException('Each allocation must identify exactly one invoice or payable.', 'validation_failed', ['allocations' => ['Choose exactly one target.']], 422);
                }
                $amount = Decimal::round((string) $row['amount'], 2);
                if (Decimal::cmp($amount, '0') <= 0) {
                    throw new BusinessRuleException('Allocation amount must be positive.', 'validation_failed', ['amount' => ['Must be positive.']], 422);
                }
                $totalThisCall = Decimal::add($totalThisCall, $amount);

                if (Decimal::cmp($totalThisCall, $locked->unallocated_amount) > 0) {
                    throw new BusinessRuleException('Allocations exceed the unallocated payment amount.', 'allocation_exceeds_payment');
                }

                if (! empty($row['invoice_id'])) {
                    $created[] = $this->allocateToInvoice($locked, (int) $row['invoice_id'], $amount, $actor);
                } elseif (! empty($row['payable_id'])) {
                    $created[] = $this->allocateToPayable($locked, (int) $row['payable_id'], $amount, $actor);
                } else {
                    throw new BusinessRuleException('Each allocation needs an invoice_id or payable_id.', 'validation_failed', ['allocations' => ['invoice_id or payable_id required.']], 422);
                }
            }

            $locked->unallocated_amount = Decimal::round(Decimal::sub((string) $locked->unallocated_amount, $totalThisCall), 2);
            $locked->setAttribute('updated_by', $actor->id)->save();

            return $created;
        });
    }

    public function unallocate(Payment $payment, PaymentAllocation $allocation, User $actor): Payment
    {
        $this->authorize($actor, Permission::PaymentsAllocate);

        return DB::transaction(function () use ($payment, $allocation, $actor) {
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            $alloc = PaymentAllocation::query()->where('payment_id', $locked->id)->lockForUpdate()->findOrFail($allocation->id);

            if ($alloc->invoice_id) {
                $invoice = Invoice::query()->lockForUpdate()->findOrFail($alloc->invoice_id);
                if (! in_array($invoice->status, [InvoiceStatus::PartiallyPaid, InvoiceStatus::Paid], true)) {
                    throw new BusinessRuleException("Invoice {$invoice->status->value} cannot have its allocation removed.", 'invalid_status_transition');
                }
                $this->invoices->updatePaidAmount($invoice, Decimal::mul((string) $alloc->invoice_ccy_amount, '-1'));
            } elseif ($alloc->payable_id) {
                $payable = Payable::query()->lockForUpdate()->findOrFail($alloc->payable_id);
                if (! in_array($payable->status, [PayableStatus::PartiallyPaid, PayableStatus::Paid], true)) {
                    throw new BusinessRuleException("Payable {$payable->status->value} cannot have its allocation removed.", 'invalid_status_transition');
                }
                $this->payables->updatePaidAmount($payable, Decimal::mul((string) $alloc->invoice_ccy_amount, '-1'));
            }

            $locked->unallocated_amount = Decimal::round(Decimal::add((string) $locked->unallocated_amount, (string) $alloc->allocated_amount), 2);
            $locked->setAttribute('updated_by', $actor->id)->save();
            activity('payments')->performedOn($locked)->causedBy($actor)->event('unallocated')
                ->withProperties($alloc->only(['invoice_id', 'payable_id', 'allocated_amount', 'invoice_ccy_amount', 'fx_difference_base']))
                ->log('Payment allocation released');
            $alloc->delete();

            return $locked->refresh();
        });
    }

    /** Reverses a recorded payment: all allocations are undone and the payment is marked reversed. */
    public function reverse(Payment $payment, User $actor, string $reason): Payment
    {
        $this->authorize($actor, Permission::PaymentsReverse);

        return DB::transaction(function () use ($payment, $actor, $reason) {
            $locked = Payment::query()->lockForUpdate()->with('allocations')->findOrFail($payment->id);
            if ($locked->isReversed()) {
                throw new BusinessRuleException('This payment is already reversed.', 'invalid_status_transition');
            }
            foreach ($locked->allocations as $alloc) {
                $this->unallocate($locked, $alloc, $actor);
            }
            $locked->refresh();
            $locked->fill(['status' => PaymentStatus::Reversed, 'remarks' => trim(($locked->remarks ? $locked->remarks."\n" : '')."Reversed: {$reason}"), 'updated_by' => $actor->id])->save();

            return $locked;
        });
    }

    public function delete(Payment $payment, User $actor): void
    {
        $this->authorize($actor, Permission::PaymentsCreate);

        DB::transaction(function () use ($payment) {
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            if ($locked->allocations()->exists()) {
                throw new BusinessRuleException('A payment with allocations cannot be deleted. Reverse it instead.', 'payment_has_allocations');
            }
            $locked->delete();
        });
    }

    /**
     * F4: invoice_ccy_amount = round(allocated × fx(pay→inv), d);
     * fx_difference_base = allocated_base_at_payment_rate − invoice_ccy_amount_base_at_invoice_rate.
     */
    private function allocateToInvoice(Payment $payment, int $invoiceId, string $amount, User $actor): PaymentAllocation
    {
        $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoiceId);
        if ($payment->direction !== PaymentDirection::RECEIVED || (int) $payment->company_id !== (int) $invoice->customer_company_id) {
            throw new BusinessRuleException('An invoice requires a received payment from its customer.', 'allocation_target_mismatch');
        }
        if (! in_array($invoice->status, [InvoiceStatus::Issued, InvoiceStatus::PartiallyPaid, InvoiceStatus::Overdue], true)) {
            throw new BusinessRuleException("Invoice {$invoice->status->value} cannot receive a payment allocation.", 'invalid_status_transition');
        }
        [$invoiceCcyAmount, $fxDifferenceBase] = $this->convertAllocation($amount, $payment, $invoice->currency, (string) $invoice->fx_rate);
        if (Decimal::cmp($invoiceCcyAmount, (string) $invoice->balance) > 0) {
            throw new BusinessRuleException("Allocation of {$invoiceCcyAmount} {$invoice->currency} exceeds the invoice balance of {$invoice->balance}.", 'allocation_exceeds_balance');
        }

        // DB unique key allows at most one allocation row per (payment, invoice); merge into it.
        $allocation = PaymentAllocation::query()->where('payment_id', $payment->id)->where('invoice_id', $invoice->id)->lockForUpdate()->first();
        if ($allocation) {
            $allocation->fill([
                'allocated_amount' => Decimal::round(Decimal::add((string) $allocation->allocated_amount, $amount), 2),
                'invoice_ccy_amount' => Decimal::round(Decimal::add((string) $allocation->invoice_ccy_amount, $invoiceCcyAmount), 2),
                'fx_difference_base' => Decimal::round(Decimal::add((string) $allocation->fx_difference_base, $fxDifferenceBase), 2),
            ])->save();
        } else {
            $allocation = PaymentAllocation::query()->create([
                'payment_id' => $payment->id,
                'invoice_id' => $invoice->id,
                'allocated_amount' => $amount,
                'invoice_ccy_amount' => $invoiceCcyAmount,
                'fx_difference_base' => $fxDifferenceBase,
                'created_by' => $actor->id,
            ]);
        }

        $this->invoices->updatePaidAmount($invoice, $invoiceCcyAmount);

        return $allocation;
    }

    private function allocateToPayable(Payment $payment, int $payableId, string $amount, User $actor): PaymentAllocation
    {
        $payable = Payable::query()->lockForUpdate()->findOrFail($payableId);
        if ($payment->direction !== PaymentDirection::PAID || (int) $payment->company_id !== (int) $payable->supplier_company_id) {
            throw new BusinessRuleException('A payable requires an outgoing payment to its supplier.', 'allocation_target_mismatch');
        }
        if (! in_array($payable->status, [PayableStatus::Approved, PayableStatus::PartiallyPaid], true)) {
            throw new BusinessRuleException("Payable {$payable->status->value} cannot receive a payment allocation.", 'invalid_status_transition');
        }
        [$payableCcyAmount, $fxDifferenceBase] = $this->convertAllocation($amount, $payment, $payable->currency, (string) $payable->fx_rate);
        if (Decimal::cmp($payableCcyAmount, (string) $payable->balance) > 0) {
            throw new BusinessRuleException("Allocation of {$payableCcyAmount} {$payable->currency} exceeds the payable balance of {$payable->balance}.", 'allocation_exceeds_balance');
        }

        $allocation = PaymentAllocation::query()->where('payment_id', $payment->id)->where('payable_id', $payable->id)->lockForUpdate()->first();
        if ($allocation) {
            $allocation->fill([
                'allocated_amount' => Decimal::round(Decimal::add((string) $allocation->allocated_amount, $amount), 2),
                'invoice_ccy_amount' => Decimal::round(Decimal::add((string) $allocation->invoice_ccy_amount, $payableCcyAmount), 2),
                'fx_difference_base' => Decimal::round(Decimal::add((string) $allocation->fx_difference_base, $fxDifferenceBase), 2),
            ])->save();
        } else {
            $allocation = PaymentAllocation::query()->create([
                'payment_id' => $payment->id,
                'payable_id' => $payable->id,
                'allocated_amount' => $amount,
                'invoice_ccy_amount' => $payableCcyAmount,
                'fx_difference_base' => $fxDifferenceBase,
                'created_by' => $actor->id,
            ]);
        }

        $this->payables->updatePaidAmount($payable, $payableCcyAmount);

        return $allocation;
    }

    /**
     * @return array{0: string, 1: string} [amount in target currency, fx_difference_base]
     */
    private function convertAllocation(string $amount, Payment $payment, string $targetCurrency, string $targetFxRate): array
    {
        $payToTargetRate = $targetCurrency === $payment->currency
            ? '1' : $this->fx->resolve($payment->currency, $targetCurrency, $payment->payment_date)['rate'];
        $targetCcyAmount = Decimal::round(Decimal::mul($amount, $payToTargetRate), 2);

        $allocatedBaseAtPaymentRate = Decimal::round(Decimal::mul($amount, (string) $payment->fx_rate), 2);
        $targetCcyAmountBaseAtTargetRate = Decimal::round(Decimal::mul($targetCcyAmount, $targetFxRate), 2);
        $fxDifferenceBase = Decimal::round(Decimal::sub($allocatedBaseAtPaymentRate, $targetCcyAmountBaseAtTargetRate), 2);

        return [$targetCcyAmount, $fxDifferenceBase];
    }

    /** F1-style snapshot: base_amount = round(amount × fx_rate_to_base, 2). */
    private function snapshotFx(Payment $payment): void
    {
        $base = (string) config('offshore.base_currency');
        $fxInfo = $this->fx->resolve($payment->currency, $base, $payment->payment_date ?? now());
        $payment->fx_rate = $fxInfo['rate'];
        $payment->base_amount = Decimal::round(Decimal::mul((string) $payment->amount, $fxInfo['rate']), 2);
    }

    /** @param array<string, mixed> $data */
    private function guardDuplicate(array $data): void
    {
        if (! empty($data['confirm_duplicate']) || empty($data['bank_reference'])) {
            return;
        }
        $exists = Payment::query()
            ->where('company_id', (int) $data['company_id'])
            ->where('bank_reference', (string) $data['bank_reference'])
            ->where('amount', Decimal::round((string) $data['amount'], 2))
            ->whereDate('payment_date', $data['payment_date'])
            ->exists();
        if ($exists) {
            throw new BusinessRuleException(
                'A payment with the same company, bank reference, amount and date already exists. Pass confirm_duplicate to proceed anyway.',
                'possible_duplicate_payment',
            );
        }
    }

    /** @param array<string, mixed> $filters */
    private function applyFilters(Builder $query, array $filters): void
    {
        if (! empty($filters['company_id'])) {
            $query->where('company_id', (int) $filters['company_id']);
        }
        if (! empty($filters['direction'])) {
            $query->where('direction', (string) $filters['direction']);
        }
        if (! empty($filters['status'])) {
            $query->where('status', (string) $filters['status']);
        }
    }

    private function authorize(User $actor, Permission $permission): void
    {
        if (! $actor->can($permission->value)) {
            throw new AuthorizationException('You do not have permission to perform this action.');
        }
    }
}
