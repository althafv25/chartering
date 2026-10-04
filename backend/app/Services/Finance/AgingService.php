<?php

namespace App\Services\Finance;

use App\Enums\InvoiceStatus;
use App\Enums\Permission;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Accounts receivable aging (F7): buckets outstanding invoice balances by
 * due_date relative to an as-of date — current, 1-30, 31-60, 61-90, over_90 —
 * grouped by customer, with totals aggregated in base currency.
 *
 * The buckets and totals are computed by the database (one grouped query), so cost does not grow with the number
 * of open invoices. Per-invoice detail is only loaded when asked for (for all customers or for one customer).
 */
class AgingService
{
    private const OPEN_STATUSES = [
        InvoiceStatus::Issued,
        InvoiceStatus::PartiallyPaid,
        InvoiceStatus::Paid,
        InvoiceStatus::Overdue,
    ];

    private const BUCKETS = ['current', '1_30', '31_60', '61_90', 'over_90'];

    /** Most invoices listed for one customer in a single response. */
    public const MAX_DETAIL_ROWS = 500;

    /**
     * @param  int|null  $customerId  limit the report to one customer
     * @param  bool  $withInvoices  include the per-invoice rows (capped at MAX_DETAIL_ROWS per response)
     * @return array<string, mixed>
     */
    public function report(User $actor, ?string $asOf = null, ?int $customerId = null, bool $withInvoices = true): array
    {
        $this->authorize($actor, Permission::InvoicesView);

        $asOfDate = $asOf !== null ? Carbon::parse($asOf)->startOfDay() : Carbon::today();
        $asOfSql = $asOfDate->toDateString();
        $base = (string) config('offshore.base_currency');

        $open = fn () => DB::table('invoices')->whereNull('deleted_at')->whereIn('status', array_map(fn (InvoiceStatus $s) => $s->value, self::OPEN_STATUSES))
            ->where('balance', '>', 0)->when($customerId !== null, fn ($q) => $q->where('customer_company_id', $customerId));

        $rows = $open()->selectRaw(
            "customer_company_id, {$this->bucketSql()} AS bucket, SUM(ROUND(balance * fx_rate, 2)) AS amount",
            [$asOfSql, $asOfSql, $asOfSql, $asOfSql],
        )->groupBy('customer_company_id', 'bucket')->get();

        $names = DB::table('companies')->whereIn('id', $rows->pluck('customer_company_id')->unique())->pluck('legal_name', 'id');

        $customers = [];
        $totals = array_fill_keys(self::BUCKETS, '0.00');
        $grandTotal = '0.00';
        foreach ($rows as $row) {
            $id = (int) $row->customer_company_id;
            $amount = Decimal::round((string) $row->amount, 2);
            $c = $customers[$id] ??= [
                'customer_company_id' => $id, 'customer_name' => $names[$id] ?? null,
                'buckets' => array_fill_keys(self::BUCKETS, '0.00'), 'total' => '0.00', 'invoices' => [],
            ];
            $c['buckets'][$row->bucket] = Decimal::round(Decimal::add($c['buckets'][$row->bucket], $amount), 2);
            $c['total'] = Decimal::round(Decimal::add($c['total'], $amount), 2);
            $customers[$id] = $c;

            $totals[$row->bucket] = Decimal::round(Decimal::add($totals[$row->bucket], $amount), 2);
            $grandTotal = Decimal::round(Decimal::add($grandTotal, $amount), 2);
        }

        if ($withInvoices && $customers !== []) {
            $detail = $open()->whereIn('customer_company_id', array_keys($customers))->orderBy('due_date')->orderBy('id')->limit(self::MAX_DETAIL_ROWS)
                ->selectRaw('id, customer_company_id, invoice_number, due_date, currency, balance, ROUND(balance * fx_rate, 2) AS base_balance, DATEDIFF(?, due_date) AS days_overdue', [$asOfSql])->get();
            foreach ($detail as $inv) {
                $days = (int) $inv->days_overdue;
                $customers[(int) $inv->customer_company_id]['invoices'][] = [
                    'id' => (int) $inv->id, 'invoice_number' => $inv->invoice_number, 'due_date' => (string) $inv->due_date, 'currency' => $inv->currency,
                    'balance' => (string) $inv->balance, 'base_balance' => Decimal::round((string) $inv->base_balance, 2), 'days_overdue' => $days, 'bucket' => $this->bucketFor($days),
                ];
            }
        }

        $customers = array_values($customers);
        usort($customers, fn (array $a, array $b) => Decimal::cmp($b['total'], $a['total']));

        return [
            'as_of' => $asOfSql,
            'base_currency' => $base,
            'customers' => $customers,
            'totals' => [...$totals, 'grand_total' => $grandTotal],
        ];
    }

    /** F7 as SQL; four `?` placeholders, each bound to the as-of date. */
    private function bucketSql(): string
    {
        return "CASE WHEN DATEDIFF(?, due_date) <= 0 THEN 'current' WHEN DATEDIFF(?, due_date) <= 30 THEN '1_30' WHEN DATEDIFF(?, due_date) <= 60 THEN '31_60' "
            ."WHEN DATEDIFF(?, due_date) <= 90 THEN '61_90' ELSE 'over_90' END";
    }

    /** F7: current (not yet due), 1-30, 31-60, 61-90, over_90 days past due. */
    private function bucketFor(int $daysOverdue): string
    {
        return match (true) {
            $daysOverdue <= 0 => 'current',
            $daysOverdue <= 30 => '1_30',
            $daysOverdue <= 60 => '31_60',
            $daysOverdue <= 90 => '61_90',
            default => 'over_90',
        };
    }

    private function authorize(User $actor, Permission $permission): void
    {
        if (! $actor->can($permission->value)) {
            throw new AuthorizationException('You do not have permission to perform this action.');
        }
    }
}
