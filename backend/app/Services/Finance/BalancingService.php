<?php

namespace App\Services\Finance;

use App\Enums\InvoiceStatus;
use App\Enums\PayableStatus;
use App\Enums\Permission;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Balancing (01 §5.23 / 03 M16): account view of receivable (open invoices) and payable (open payables) balances
 * per company, plus a calendar/cash-flow projection bucketing the same open balances by due period. Amounts are
 * base currency using each record's own FX snapshot (F1), rounded per record and summed by the database, so the
 * cost does not grow with the number of open documents.
 *
 * Receivable = open invoice balance (status issued/partially_paid/paid/overdue, balance > 0).
 * Payable = open payable balance (status approved/partially_paid, balance > 0).
 * Net position per account = receivable − payable. Cash-flow net per period = receivable − payable due in that period.
 */
class BalancingService
{
    /**
     * Account view: one row per company with any open receivable and/or payable balance.
     *
     * @param  array<string, mixed>  $filters  company_id, voyage_id
     * @return array<string, mixed>
     */
    public function accounts(User $actor, array $filters = []): array
    {
        $this->authorize($actor);

        /** @var array<int, array<string, mixed>> $accounts */
        $accounts = [];
        $sources = [
            ['query' => $this->openInvoices($filters), 'company' => 'customer_company_id', 'amount' => 'receivable', 'count' => 'receivable_count'],
            ['query' => $this->openPayables($filters), 'company' => 'supplier_company_id', 'amount' => 'payable', 'count' => 'payable_count'],
        ];
        foreach ($sources as $src) {
            $rows = $src['query']->selectRaw("{$src['company']} AS company_id, SUM(ROUND(balance * fx_rate, 2)) AS amount, COUNT(*) AS n")->groupBy($src['company'])->get();
            foreach ($rows as $r) {
                $id = (int) $r->company_id;
                $accounts[$id] ??= $this->blankAccount($id);
                $accounts[$id][$src['amount']] = Decimal::round((string) $r->amount, 2);
                $accounts[$id][$src['count']] = (int) $r->n;
            }
        }
        $names = DB::table('companies')->whereIn('id', array_keys($accounts))->pluck('legal_name', 'id');

        $rows = [];
        $totals = ['receivable' => '0.00', 'payable' => '0.00'];
        foreach ($accounts as $id => $row) {
            $row['company_name'] = $names[$id] ?? null;
            $row['net'] = Decimal::round(Decimal::sub($row['receivable'], $row['payable']), 2);
            $rows[] = $row;
            $totals['receivable'] = Decimal::round(Decimal::add($totals['receivable'], $row['receivable']), 2);
            $totals['payable'] = Decimal::round(Decimal::add($totals['payable'], $row['payable']), 2);
        }
        usort($rows, fn (array $a, array $b) => Decimal::cmp(Decimal::add($b['receivable'], $b['payable']), Decimal::add($a['receivable'], $a['payable'])));
        $totals['net'] = Decimal::round(Decimal::sub($totals['receivable'], $totals['payable']), 2);

        return ['base_currency' => (string) config('offshore.base_currency'), 'accounts' => $rows, 'totals' => $totals];
    }

    /**
     * Calendar / cash-flow: open receivable and payable balances bucketed by due date into
     * calendar-month periods between `from` and `to` (defaults: this month .. +5 months),
     * plus an "overdue" bucket (due before today) and a "beyond" bucket (due after `to`).
     *
     * @param  array<string, mixed>  $filters  company_id, voyage_id, from, to
     * @return array<string, mixed>
     */
    public function cashFlow(User $actor, array $filters = []): array
    {
        $this->authorize($actor);

        $from = isset($filters['from']) ? Carbon::parse($filters['from'])->startOfMonth() : Carbon::today()->startOfMonth();
        $to = isset($filters['to']) ? Carbon::parse($filters['to'])->startOfMonth() : $from->copy()->addMonths(5);
        if ($to->lessThan($from)) {
            $to = $from->copy();
        }

        /** @var array<string, array<string, mixed>> $periods key => row, in display order */
        $periods = ['overdue' => ['period' => 'overdue', 'label' => 'Overdue', 'receivable' => '0.00', 'payable' => '0.00']];
        for ($cursor = $from->copy(); $cursor->lessThanOrEqualTo($to); $cursor->addMonth()) {
            $key = $cursor->format('Y-m');
            $periods[$key] = ['period' => $key, 'label' => $cursor->format('M Y'), 'receivable' => '0.00', 'payable' => '0.00'];
        }
        $periods['beyond'] = ['period' => 'beyond', 'label' => 'Beyond', 'receivable' => '0.00', 'payable' => '0.00'];

        $bindings = [Carbon::today()->toDateString(), $to->copy()->endOfMonth()->toDateString(), $from->toDateString(), $from->format('Y-m')];
        $periodSql = "CASE WHEN due_date < ? THEN 'overdue' WHEN due_date > ? THEN 'beyond' WHEN due_date < ? THEN ? ELSE DATE_FORMAT(due_date, '%Y-%m') END";

        foreach ([['receivable', $this->openInvoices($filters)], ['payable', $this->openPayables($filters)]] as [$column, $query]) {
            foreach ($query->selectRaw("{$periodSql} AS period, SUM(ROUND(balance * fx_rate, 2)) AS amount", $bindings)->groupBy('period')->get() as $r) {
                $key = isset($periods[$r->period]) ? $r->period : 'beyond';
                $periods[$key][$column] = Decimal::round(Decimal::add($periods[$key][$column], (string) $r->amount), 2);
            }
        }

        $rows = [];
        $totals = ['receivable' => '0.00', 'payable' => '0.00'];
        foreach ($periods as $row) {
            $row['net'] = Decimal::round(Decimal::sub($row['receivable'], $row['payable']), 2);
            $rows[] = $row;
            $totals['receivable'] = Decimal::round(Decimal::add($totals['receivable'], $row['receivable']), 2);
            $totals['payable'] = Decimal::round(Decimal::add($totals['payable'], $row['payable']), 2);
        }
        $totals['net'] = Decimal::round(Decimal::sub($totals['receivable'], $totals['payable']), 2);

        return ['base_currency' => (string) config('offshore.base_currency'), 'from' => $from->toDateString(), 'to' => $to->toDateString(), 'periods' => $rows, 'totals' => $totals];
    }

    /** @param array<string, mixed> $filters */
    private function openInvoices(array $filters): Builder
    {
        $statuses = [InvoiceStatus::Issued, InvoiceStatus::PartiallyPaid, InvoiceStatus::Paid, InvoiceStatus::Overdue];
        $q = DB::table('invoices')->whereNull('deleted_at')->whereIn('status', array_map(fn (InvoiceStatus $s) => $s->value, $statuses))->where('balance', '>', 0);
        if (! empty($filters['company_id'])) {
            $q->where('customer_company_id', (int) $filters['company_id']);
        }
        if (! empty($filters['voyage_id'])) {
            $q->where('voyage_id', (int) $filters['voyage_id']);
        }

        return $q;
    }

    /** @param array<string, mixed> $filters */
    private function openPayables(array $filters): Builder
    {
        $q = DB::table('payables')->whereNull('deleted_at')->whereIn('status', [PayableStatus::Approved->value, PayableStatus::PartiallyPaid->value])->where('balance', '>', 0);
        if (! empty($filters['company_id'])) {
            $q->where('supplier_company_id', (int) $filters['company_id']);
        }
        if (! empty($filters['voyage_id'])) {
            $q->where('voyage_id', (int) $filters['voyage_id']);
        }

        return $q;
    }

    /** @return array<string, mixed> */
    private function blankAccount(int $companyId): array
    {
        return ['company_id' => $companyId, 'company_name' => null, 'receivable' => '0.00', 'payable' => '0.00', 'receivable_count' => 0, 'payable_count' => 0];
    }

    private function authorize(User $actor): void
    {
        if (! $actor->can(Permission::BalancingView->value)) {
            throw new AuthorizationException('You do not have permission to perform this action.');
        }
    }
}
