<?php

namespace App\Services\Finance;

use App\Enums\Permission;
use App\Enums\VoyageExpenseStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\ExpenseCategory;
use App\Models\LaytimeCalculation;
use App\Models\PortDa;
use App\Models\User;
use App\Models\VoyageExpense;
use App\Services\Chartering\ApprovalGuard;
use App\Services\ExchangeRateService;
use App\Support\Decimal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Voyage expense lines (from port DA, bunker stems, payables, offshore
 * activities, or manual entries).
 *
 * Lifecycle: draft → confirmed → approved → paid; draft|confirmed|approved → cancelled.
 * Only drafts are editable (G-04). Approval requires a different user than
 * the one who confirmed it (APR-02). FX is snapshotted at save time (F1).
 *
 * F1: base_amount = round(amount × fx_rate_to_base, base_decimals)
 */
class VoyageExpenseService
{
    private const EDITABLE_FIELDS = [
        'voyage_id', 'contract_id', 'expense_category_id', 'description', 'is_estimate',
        'quantity', 'rate', 'currency', 'supplier_company_id', 'incurred_at', 'remarks',
    ];

    public function __construct(
        private readonly ExchangeRateService $fx,
        private readonly ApprovalGuard $approvals,
    ) {}

    /** @return LengthAwarePaginator<int, VoyageExpense> */
    public function paginate(User $actor, array $filters, int $perPage): LengthAwarePaginator
    {
        $this->authorize($actor, Permission::ExpensesView);

        $query = VoyageExpense::query()->with(['voyage', 'contract', 'category', 'supplier', 'source']);
        $this->applyFilters($query, $filters);

        return $query->latest('id')->paginate($perPage);
    }

    public function find(User $actor, int $id): VoyageExpense
    {
        $this->authorize($actor, Permission::ExpensesView);

        return VoyageExpense::query()->with(['voyage', 'contract', 'category', 'supplier', 'source'])->findOrFail($id);
    }

    /** @param array<string, mixed> $data */
    public function create(array $data, User $actor): VoyageExpense
    {
        $this->authorize($actor, Permission::ExpensesCreate);

        return DB::transaction(function () use ($data, $actor) {
            $expense = new VoyageExpense(['created_by' => $actor->id]);
            $expense->fill(Arr::only($data, self::EDITABLE_FIELDS));
            $this->applyAmounts($expense, $data);
            $expense->setAttribute('updated_by', $actor->id);
            $expense->save();

            return $expense;
        });
    }

    /** Creates an expense attributed to a polymorphic source (port DA, bunker stem, payable, offshore activity). */
    public function createFromSource(Model $source, array $data, User $actor): VoyageExpense
    {
        $this->authorize($actor, Permission::ExpensesCreate);

        return DB::transaction(function () use ($source, $data, $actor) {
            $expense = new VoyageExpense(['created_by' => $actor->id]);
            $expense->fill(Arr::only($data, self::EDITABLE_FIELDS));
            $expense->source()->associate($source);
            $this->applyAmounts($expense, $data);
            $expense->setAttribute('updated_by', $actor->id);
            $expense->save();

            return $expense;
        });
    }

    /** @param array<string, mixed> $data */
    public function update(VoyageExpense $expense, array $data, User $actor): VoyageExpense
    {
        $this->authorize($actor, Permission::ExpensesUpdate);

        return DB::transaction(function () use ($expense, $data, $actor) {
            $locked = VoyageExpense::query()->lockForUpdate()->findOrFail($expense->id);
            if (! $locked->isEditable()) {
                throw new BusinessRuleException("A {$locked->status->value} expense line cannot be edited.", 'expense_read_only');
            }
            $locked->fill(Arr::only($data, self::EDITABLE_FIELDS));
            $this->applyAmounts($locked, $data);
            $locked->setAttribute('updated_by', $actor->id);
            $locked->save();

            return $locked;
        });
    }

    public function confirm(VoyageExpense $expense, User $actor): VoyageExpense
    {
        $this->authorize($actor, Permission::ExpensesUpdate);

        return DB::transaction(function () use ($expense, $actor) {
            $locked = VoyageExpense::query()->lockForUpdate()->findOrFail($expense->id);
            if ($locked->status !== VoyageExpenseStatus::Draft) {
                throw new BusinessRuleException('Only a draft expense line can be confirmed.', 'invalid_status_transition');
            }
            $locked->fill(['status' => VoyageExpenseStatus::Confirmed, 'created_by' => $locked->created_by ?? $actor->id, 'updated_by' => $actor->id])->save();

            return $locked;
        });
    }

    public function approve(VoyageExpense $expense, User $actor): VoyageExpense
    {
        $this->authorize($actor, Permission::ExpensesApprove);

        return DB::transaction(function () use ($expense, $actor) {
            $locked = VoyageExpense::query()->lockForUpdate()->findOrFail($expense->id);
            if ($locked->status !== VoyageExpenseStatus::Confirmed) {
                throw new BusinessRuleException('Only a confirmed expense line can be approved.', 'invalid_status_transition');
            }
            $this->approvals->assertNotSelfDecision($locked->created_by, $actor);
            $locked->fill(['status' => VoyageExpenseStatus::Approved, 'updated_by' => $actor->id])->save();

            return $locked;
        });
    }

    public function markPaid(VoyageExpense $expense): void
    {
        $expense->fill(['status' => VoyageExpenseStatus::Paid])->save();
    }

    /**
     * DA-02: approved final DA items become voyage expenses (source `port_da`).
     * Re-approval (after a reject → re-submit → approve cycle) updates the
     * linked expense in place rather than duplicating it, as long as it has
     * not been paid. Must be called from within the caller's transaction
     * (e.g. PortDaService::approve), which already holds the DA row lock.
     * No permission check: this is a system-driven side effect of DA
     * approval, not a direct user action on expenses.
     */
    public function syncFromPortDa(PortDa $da, User $actor): void
    {
        if ($da->da_type !== 'final') {
            return;
        }

        $items = $da->items()->with('category')->get();
        foreach ($items as $item) {
            $amount = $item->actual_amount;
            if ($amount === null || Decimal::isZero((string) $amount)) {
                continue;
            }

            $expenseCategoryId = $item->category?->expense_category_id;
            if ($expenseCategoryId === null) {
                throw new BusinessRuleException(
                    "DA cost category \"{$item->category?->name}\" has no linked expense category; cannot create a voyage expense.",
                    'da_cost_category_unmapped'
                );
            }

            $existing = VoyageExpense::query()
                ->where('source_type', $da->getMorphClass())
                ->where('source_id', $da->id)
                ->where('expense_category_id', $expenseCategoryId)
                ->lockForUpdate()
                ->first();

            if ($existing !== null && $existing->status === VoyageExpenseStatus::Paid) {
                // Already settled; leave untouched per DA-02.
                continue;
            }

            $data = [
                'voyage_id' => $da->voyage_id,
                'expense_category_id' => $expenseCategoryId,
                'description' => $item->description ?? ($item->category?->name ?? 'Port DA cost'),
                'is_estimate' => false,
                'quantity' => null,
                'rate' => null,
                'amount' => (string) $amount,
                'currency' => $da->currency,
                'supplier_company_id' => $da->agent_company_id,
                'incurred_at' => $da->approved_at ?? now(),
            ];

            if ($existing !== null) {
                // Update in place regardless of draft/confirmed/approved status (but
                // never paid, excluded above) — DA-02 promises an in-place update,
                // not a status reset, on re-approval.
                $existing->fill(Arr::only($data, self::EDITABLE_FIELDS));
                $this->applyAmounts($existing, $data);
                $existing->setAttribute('updated_by', $actor->id);
                $existing->save();

                continue;
            }

            $expense = new VoyageExpense(['created_by' => $actor->id]);
            $expense->fill(Arr::only($data, self::EDITABLE_FIELDS));
            $expense->source()->associate($da);
            $this->applyAmounts($expense, $data);
            $expense->setAttribute('updated_by', $actor->id);
            $expense->fill(['status' => VoyageExpenseStatus::Confirmed]);
            $expense->save();
        }
    }

    public function cancel(VoyageExpense $expense, User $actor, string $reason): VoyageExpense
    {
        $this->authorize($actor, Permission::ExpensesUpdate);

        return DB::transaction(function () use ($expense, $actor, $reason) {
            $locked = VoyageExpense::query()->lockForUpdate()->findOrFail($expense->id);
            if (! in_array($locked->status, [VoyageExpenseStatus::Draft, VoyageExpenseStatus::Confirmed, VoyageExpenseStatus::Approved], true)) {
                throw new BusinessRuleException("A {$locked->status->value} expense line cannot be cancelled.", 'invalid_status_transition');
            }
            $locked->fill(['status' => VoyageExpenseStatus::Cancelled, 'remarks' => trim(($locked->remarks ? $locked->remarks."\n" : '')."Cancelled: {$reason}"), 'updated_by' => $actor->id])->save();

            return $locked;
        });
    }

    /**
     * Laytime→Expense automation: Create or update voyage expense from agreed laytime (despatch).
     * Called by LaytimeService when a laytime calculation is agreed and has despatch to be paid.
     */
    public function syncFromLaytime(LaytimeCalculation $laytime, User $actor): void
    {
        DB::transaction(function () use ($laytime, $actor) {
            // Find existing expense linked to this laytime
            $expense = VoyageExpense::query()
                ->where('source_type', $laytime->getMorphClass())
                ->where('source_id', $laytime->id)
                ->lockForUpdate()
                ->first();

            if ($expense !== null && $expense->status === VoyageExpenseStatus::Paid) {
                return; // Already paid, don't update
            }

            // Find appropriate expense category for despatch
            $category = ExpenseCategory::query()
                ->where('name', 'LIKE', '%Despatch%')
                ->orWhere('name', 'LIKE', '%Laytime%')
                ->first();
            if (! $category) {
                $category = ExpenseCategory::query()->first();
            }

            $portCall = $laytime->portCall;
            $portName = $portCall?->port?->name ?? 'Port';
            // Payee is left empty: despatch is owed to the charterer, not a port party, and the payee rule is not defined yet (BR-LT-05 [CONFIRM]).
            $supplierCompany = null;

            $data = [
                'voyage_id' => $laytime->voyage_id,
                'contract_id' => $laytime->contract_id,
                'expense_category_id' => $category?->id,
                'description' => "Despatch - {$portName} ({$laytime->calculation_type})",
                'is_estimate' => false,
                'quantity' => abs((float) $laytime->difference_hours), // Negative difference = despatch
                'rate' => $laytime->despatch_rate_per_day,
                'amount' => $laytime->despatch_amount,
                'currency' => $laytime->currency,
                'supplier_company_id' => $supplierCompany,
                'incurred_at' => $laytime->laytime_completed_at ?? now(),
            ];

            if ($expense) {
                // Update existing expense if not paid
                $expense->fill(Arr::only($data, self::EDITABLE_FIELDS));
                $expense->source()->associate($laytime);
                $this->applyAmounts($expense, $data);
                $expense->setAttribute('updated_by', $actor->id);
                $expense->save();
            } else {
                // Create new expense
                $expense = new VoyageExpense(['created_by' => $actor->id]);
                $expense->fill(Arr::only($data, self::EDITABLE_FIELDS));
                $expense->source()->associate($laytime);
                $this->applyAmounts($expense, $data);
                $expense->setAttribute('updated_by', $actor->id);
                $expense->fill(['status' => VoyageExpenseStatus::Confirmed]);
                $expense->save();
            }
        });
    }

    public function delete(VoyageExpense $expense, User $actor): void
    {
        $this->authorize($actor, Permission::ExpensesUpdate);

        DB::transaction(function () use ($expense) {
            $locked = VoyageExpense::query()->lockForUpdate()->findOrFail($expense->id);
            if (! $locked->isEditable()) {
                throw new BusinessRuleException("A {$locked->status->value} expense line cannot be deleted.", 'expense_read_only');
            }
            $locked->delete();
        });
    }

    /** F1: base_amount = round(amount × fx_rate_to_base, base_decimals). Snapshots FX at save time. */
    private function applyAmounts(VoyageExpense $expense, array $data): void
    {
        $qty = $expense->quantity;
        $rate = $expense->rate;
        if (isset($data['amount'])) {
            $amount = Decimal::round((string) $data['amount'], 2);
        } elseif ($qty !== null && $rate !== null) {
            $amount = Decimal::round(Decimal::mul((string) $qty, (string) $rate), 2);
        } else {
            $amount = Decimal::round((string) ($expense->amount ?? '0'), 2);
        }

        $base = (string) config('offshore.base_currency');
        $fxInfo = $this->fx->resolve($expense->currency, $base, now());
        $baseAmount = Decimal::round(Decimal::mul($amount, $fxInfo['rate']), 2);

        $expense->fill([
            'amount' => $amount,
            'fx_rate' => $fxInfo['rate'],
            'base_amount' => $baseAmount,
        ]);
    }

    /** @param array<string, mixed> $filters */
    private function applyFilters(Builder $query, array $filters): void
    {
        if (! empty($filters['voyage_id'])) {
            $query->where('voyage_id', (int) $filters['voyage_id']);
        }
        if (! empty($filters['contract_id'])) {
            $query->where('contract_id', (int) $filters['contract_id']);
        }
        if (! empty($filters['status'])) {
            $query->where('status', (string) $filters['status']);
        }
        if (! empty($filters['supplier_company_id'])) {
            $query->where('supplier_company_id', (int) $filters['supplier_company_id']);
        }
    }

    private function authorize(User $actor, Permission $permission): void
    {
        if (! $actor->can($permission->value)) {
            throw new AuthorizationException('You do not have permission to perform this action.');
        }
    }
}
