<?php

namespace App\Services\Finance;

use App\Enums\PayableStatus;
use App\Enums\Permission;
use App\Enums\VoyageExpenseStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\ExpenseCategory;
use App\Models\Payable;
use App\Models\User;
use App\Models\VoyageExpense;
use App\Services\Chartering\ApprovalGuard;
use App\Services\ExchangeRateService;
use App\Services\SequenceService;
use App\Support\Decimal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Accounts payable (supplier invoices received).
 *
 * Lifecycle: draft → approved → partially_paid → paid; draft|approved → cancelled.
 * Approval requires a different user than the creator (APR-02). Approving a
 * payable creates/links voyage expenses so costs flow into voyage P&L (DA-02
 * style: re-approval after an edit updates the linked expense in place while
 * it is not yet paid).
 *
 * F1: base_total = round(total × fx_rate_to_base, 2)
 * F3-style: subtotal + tax = total; balance = total − amount_paid (DB generated column)
 */
class PayableService
{
    private const EDITABLE_FIELDS = [
        'supplier_company_id', 'supplier_invoice_ref', 'voyage_id', 'issue_date', 'due_date', 'currency',
        'subtotal', 'tax', 'remarks',
    ];

    public function __construct(
        private readonly ExchangeRateService $fx,
        private readonly SequenceService $sequences,
        private readonly ApprovalGuard $approvals,
    ) {}

    /** @return LengthAwarePaginator<int, Payable> */
    public function paginate(User $actor, array $filters, int $perPage): LengthAwarePaginator
    {
        $this->authorize($actor, Permission::PayablesView);

        $query = Payable::query()->with(['supplier', 'voyage']);
        $this->applyFilters($query, $filters);

        return $query->latest('id')->paginate($perPage);
    }

    public function find(User $actor, int $id): Payable
    {
        $this->authorize($actor, Permission::PayablesView);

        return Payable::query()->with(['supplier', 'voyage', 'allocations.payment', 'voyageExpenses'])->findOrFail($id);
    }

    /** @param array<string, mixed> $data */
    public function create(array $data, User $actor): Payable
    {
        $this->authorize($actor, Permission::PayablesCreate);

        return DB::transaction(function () use ($data, $actor) {
            $payable = new Payable(['created_by' => $actor->id, 'updated_by' => $actor->id]);
            $payable->fill(Arr::only($data, self::EDITABLE_FIELDS));
            $payable->payable_number = $this->sequences->next('payable', 'PAYB-'.now()->format('Y').'-');
            $this->applyTotals($payable);
            $payable->save();

            return $payable;
        });
    }

    /** @param array<string, mixed> $data */
    public function update(Payable $payable, array $data, User $actor): Payable
    {
        $this->authorize($actor, Permission::PayablesCreate);

        return DB::transaction(function () use ($payable, $data, $actor) {
            $locked = Payable::query()->lockForUpdate()->findOrFail($payable->id);
            $locked->assertLockVersion(isset($data['lock_version']) ? (int) $data['lock_version'] : null);
            if (! $locked->isEditable()) {
                throw new BusinessRuleException("A {$locked->status->value} payable cannot be edited.", 'payable_read_only');
            }
            $locked->fill([...Arr::only($data, self::EDITABLE_FIELDS), 'updated_by' => $actor->id]);
            $this->applyTotals($locked);
            $locked->save();

            return $locked;
        });
    }

    /**
     * Approves the payable and books its lines as voyage expenses (one
     * per voyage, source = this payable) so costs feed voyage P&L.
     */
    public function approve(Payable $payable, User $actor): Payable
    {
        $this->authorize($actor, Permission::PayablesApprove);

        return DB::transaction(function () use ($payable, $actor) {
            $locked = Payable::query()->lockForUpdate()->findOrFail($payable->id);
            if ($locked->status !== PayableStatus::Draft) {
                throw new BusinessRuleException('Only a draft payable can be approved.', 'invalid_status_transition');
            }
            $this->approvals->assertNotSelfDecision($locked->created_by, $actor);

            $this->syncVoyageExpense($locked, $actor);

            $locked->fill(['status' => PayableStatus::Approved, 'approved_by' => $actor->id, 'approved_at' => now(), 'updated_by' => $actor->id])->save();

            return $locked;
        });
    }

    /**
     * Re-approving after an edit updates the linked voyage expense in place,
     * as long as the payable is not yet (partially) paid.
     */
    public function reapprove(Payable $payable, User $actor): Payable
    {
        $this->authorize($actor, Permission::PayablesApprove);

        return DB::transaction(function () use ($payable, $actor) {
            $locked = Payable::query()->lockForUpdate()->findOrFail($payable->id);
            if ($locked->status !== PayableStatus::Approved) {
                throw new BusinessRuleException('Only an approved payable can be re-approved.', 'invalid_status_transition');
            }
            if (Decimal::cmp((string) $locked->amount_paid, '0') > 0) {
                throw new BusinessRuleException('A partially or fully paid payable cannot be re-approved.', 'payable_already_paid');
            }
            $this->syncVoyageExpense($locked, $actor);
            $locked->setAttribute('updated_by', $actor->id)->save();

            return $locked;
        });
    }

    public function cancel(Payable $payable, User $actor, string $reason): Payable
    {
        $this->authorize($actor, Permission::PayablesCreate);

        return DB::transaction(function () use ($payable, $actor, $reason) {
            $locked = Payable::query()->lockForUpdate()->findOrFail($payable->id);
            if (! in_array($locked->status, [PayableStatus::Draft, PayableStatus::Approved], true)) {
                throw new BusinessRuleException("A {$locked->status->value} payable cannot be cancelled.", 'invalid_status_transition');
            }
            /** @var Collection<int, VoyageExpense> $expenses */
            $expenses = $locked->voyageExpenses()->get();
            foreach ($expenses as $expense) {
                if ($expense->isEditable() || $expense->status === VoyageExpenseStatus::Confirmed || $expense->status === VoyageExpenseStatus::Approved) {
                    $expense->fill(['status' => VoyageExpenseStatus::Cancelled])->save();
                }
            }
            $locked->fill(['status' => PayableStatus::Cancelled, 'remarks' => trim(($locked->remarks ? $locked->remarks."\n" : '')."Cancelled: {$reason}"), 'updated_by' => $actor->id])->save();

            return $locked;
        });
    }

    public function delete(Payable $payable, User $actor): void
    {
        $this->authorize($actor, Permission::PayablesCreate);

        DB::transaction(function () use ($payable) {
            $locked = Payable::query()->lockForUpdate()->findOrFail($payable->id);
            if (! $locked->isEditable()) {
                throw new BusinessRuleException("A {$locked->status->value} payable cannot be deleted.", 'payable_read_only');
            }
            $locked->delete();
        });
    }

    /**
     * Called by PaymentService inside its own transaction/lock after allocating or
     * unallocating funds. Recomputes amount_paid from the given delta (positive to
     * allocate, negative to unallocate) and transitions status (PAY-02).
     */
    public function updatePaidAmount(Payable $payable, string $deltaInPayableCurrency): Payable
    {
        $payable->amount_paid = Decimal::round(Decimal::add((string) $payable->amount_paid, $deltaInPayableCurrency), 2);
        $balance = Decimal::round(Decimal::sub((string) $payable->total, (string) $payable->amount_paid), 2);
        $payable->status = Decimal::isZero($balance)
            ? PayableStatus::Paid
            : (Decimal::cmp($payable->amount_paid, '0') > 0 ? PayableStatus::PartiallyPaid : PayableStatus::Approved);
        $payable->save();

        return $payable;
    }

    /** F3-style: total = subtotal + tax. F1: base_total = round(total × fx_rate, 2). */
    private function applyTotals(Payable $payable): void
    {
        $subtotal = Decimal::round((string) ($payable->subtotal ?? '0'), 2);
        $tax = Decimal::round((string) ($payable->tax ?? '0'), 2);
        $total = Decimal::round(Decimal::add($subtotal, $tax), 2);

        $base = (string) config('offshore.base_currency');
        $fxInfo = $this->fx->resolve($payable->currency, $base, $payable->issue_date ?? now());
        $baseTotal = Decimal::round(Decimal::mul($total, $fxInfo['rate']), 2);

        $payable->fill(['subtotal' => $subtotal, 'tax' => $tax, 'total' => $total, 'fx_rate' => $fxInfo['rate'], 'base_total' => $baseTotal]);
    }

    /** Creates or updates the single voyage expense sourced from this payable. */
    private function syncVoyageExpense(Payable $payable, User $actor): void
    {
        if (! $payable->voyage_id) {
            return; // Nothing to book against voyage P&L without a voyage.
        }

        /** @var VoyageExpense|null $expense */
        $expense = $payable->voyageExpenses()->first();
        $values = [
            'voyage_id' => $payable->voyage_id,
            'expense_category_id' => $expense?->expense_category_id ?? $this->defaultExpenseCategoryId(),
            'description' => "Payable {$payable->payable_number} — {$payable->supplier_invoice_ref}",
            'currency' => $payable->currency,
            'fx_rate' => $payable->fx_rate,
            'amount' => $payable->total,
            'base_amount' => $payable->base_total,
            'supplier_company_id' => $payable->supplier_company_id,
            'status' => VoyageExpenseStatus::Approved,
            'incurred_at' => $payable->issue_date,
            'updated_by' => $actor->id,
        ];

        if ($expense) {
            $expense->fill($values)->save();
        } else {
            $values['created_by'] = $actor->id;
            $payable->voyageExpenses()->create($values);
        }
    }

    private function defaultExpenseCategoryId(): int
    {
        /** @var class-string<Model> $model */
        $model = ExpenseCategory::class;
        $id = $model::query()->where('code', 'other')->value('id') ?? $model::query()->value('id');
        if ($id === null) {
            throw new BusinessRuleException('No expense category is configured. Create one under Masters before approving payables.', 'expense_category_missing', [], 422);
        }

        return (int) $id;
    }

    /** @param array<string, mixed> $filters */
    private function applyFilters(Builder $query, array $filters): void
    {
        if (! empty($filters['supplier_company_id'])) {
            $query->where('supplier_company_id', (int) $filters['supplier_company_id']);
        }
        if (! empty($filters['voyage_id'])) {
            $query->where('voyage_id', (int) $filters['voyage_id']);
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
