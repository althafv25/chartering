<?php

namespace Tests\Feature\Finance;

use App\Enums\UserRole;
use App\Models\CaptainReport;
use App\Models\CaptainReportFuelLine;
use App\Models\Company;
use App\Models\ExpenseCategory;
use App\Models\FuelType;
use App\Models\Invoice;
use App\Models\LaytimeCalculation;
use App\Models\OffHireEvent;
use App\Models\OffshoreActivity;
use App\Models\OffshoreActivityType;
use App\Models\Payable;
use App\Models\PortDa;
use App\Models\RevenueCategory;
use App\Models\VoyageExpense;
use App\Models\VoyageRevenue;
use Tests\Feature\Operations\OperationsTestCase;

class ReportsAndDashboardTest extends OperationsTestCase
{
    private function revenue(int $voyage, string $amount, string $status, string $commission = '0.00', bool $estimate = false, string $description = 'Freight'): void
    {
        VoyageRevenue::query()->create([
            'voyage_id' => $voyage, 'revenue_category_id' => RevenueCategory::query()->where('code', 'FREIGHT')->value('id'), 'description' => $description,
            'currency' => 'USD', 'fx_rate' => '1', 'amount' => $amount, 'base_amount' => $amount, 'commission_pct_total' => '0', 'commission_amount' => $commission,
            'status' => $status, 'is_estimate' => $estimate,
        ]);
    }

    private function expense(int $voyage, string $amount, string $status): void
    {
        VoyageExpense::query()->create([
            'voyage_id' => $voyage, 'expense_category_id' => ExpenseCategory::query()->value('id'), 'description' => 'Port costs',
            'currency' => 'USD', 'fx_rate' => '1', 'amount' => $amount, 'base_amount' => $amount, 'status' => $status,
        ]);
    }

    public function test_voyage_financials_use_confirmed_non_estimate_lines_only(): void
    {
        $id = $this->voyage();
        $this->revenue($id, '1000.00', 'confirmed', '100.00');
        $this->revenue($id, '500.00', 'draft');                      // excluded: draft
        $this->revenue($id, '400.00', 'confirmed', '0.00', true);    // excluded: estimate
        $this->expense($id, '300.00', 'approved');
        $this->expense($id, '999.00', 'cancelled');                  // excluded: cancelled

        $finance = $this->userWithRole(UserRole::Finance);
        $data = $this->as($finance)->getJson("/api/v1/voyages/{$id}/financials")->assertOk()->json('data');
        $rows = collect($data['rows'])->keyBy('metric');

        $this->assertSame('1000.00', $rows['gross_revenue']['actual']);
        $this->assertSame('100.00', $rows['commission']['actual']);
        $this->assertSame('300.00', $rows['total_costs']['actual']);
        $this->assertSame('600.00', $rows['net_profit']['actual']);
        $this->assertSame('700.00', $data['gross_profit']);
        $this->assertSame('60.00', $data['margin_pct']);
        $this->assertSame('Freight', $data['revenue_by_category'][0]['category']);
        $this->assertSame('1000.00', $data['revenue_by_category'][0]['amount']);

        // Marine/ops users without commercial.financials.view cannot see it.
        $this->as($this->ops)->getJson("/api/v1/voyages/{$id}/financials")->assertForbidden();
    }

    public function test_reports_catalogue_is_permission_filtered_and_csv_is_sanitised(): void
    {
        $id = $this->voyage();
        $this->revenue($id, '250.00', 'confirmed', '0.00', false, '=HYPERLINK("http://x")');

        $finance = $this->userWithRole(UserRole::Finance);
        $slugs = array_column($this->as($finance)->getJson('/api/v1/reports')->assertOk()->json('data'), 'slug');
        $this->assertEqualsCanonicalizing(['voyage-pnl', 'outstanding-invoices', 'revenue', 'expenses', 'estimated-vs-actual', 'vessel-profitability', 'voyage-status', 'fleet-status', 'contract-status', 'bunker-consumption', 'port-cost', 'offshore-activities', 'laytime-demurrage', 'chartering-activity', 'vessel-utilization', 'vessel-performance'], $slugs);

        $opsSlugs = array_column($this->as($this->ops)->getJson('/api/v1/reports')->assertOk()->json('data'), 'slug');
        $this->assertEqualsCanonicalizing(['outstanding-invoices', 'voyage-status', 'fleet-status', 'contract-status', 'bunker-consumption', 'port-cost', 'offshore-activities', 'laytime-demurrage', 'chartering-activity', 'vessel-utilization', 'vessel-performance'], $opsSlugs);
        $this->as($this->ops)->getJson('/api/v1/reports/vessel-profitability')->assertForbidden();
        $this->as($this->ops)->getJson('/api/v1/reports/voyage-pnl')->assertForbidden();

        $pnl = $this->as($finance)->getJson('/api/v1/reports/voyage-pnl')->assertOk()->json('data');
        $this->assertSame('250.00', $pnl['rows'][0]['revenue']);

        $csv = $this->as($finance)->get('/api/v1/reports/revenue?format=csv');
        $csv->assertOk();
        $this->assertStringContainsString('text/csv', (string) $csv->headers->get('Content-Type'));
        $body = $csv->streamedContent();
        $this->assertStringContainsString("'=HYPERLINK", $body);        // formula neutralised
        $this->assertStringNotContainsString(',=HYPERLINK', $body);

        $pdf = $this->as($finance)->get('/api/v1/reports/revenue?format=pdf');
        $pdf->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $pdf->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $pdf->streamedContent());

        // Read-only users may view a report but not export it.
        $readOnly = $this->userWithRole(UserRole::ReadOnly);
        $this->as($readOnly)->getJson('/api/v1/reports/revenue')->assertOk();
        $this->as($readOnly)->get('/api/v1/reports/revenue?format=pdf')->assertForbidden();
        $this->as($readOnly)->get('/api/v1/reports/revenue?format=csv')->assertForbidden();
        $this->as($finance)->getJson('/api/v1/reports/nope')->assertStatus(404);
    }

    public function test_estimated_vs_actual_and_vessel_profitability(): void
    {
        $id = $this->voyage();
        $this->revenue($id, '1000.00', 'confirmed');
        $this->expense($id, '300.00', 'approved');
        $finance = $this->userWithRole(UserRole::Finance);

        $eva = $this->as($finance)->getJson('/api/v1/reports/estimated-vs-actual')->assertOk()->json('data');
        $row = $eva['rows'][0];
        $this->assertSame('1000.00', $row['act_revenue']);
        $this->assertSame('700.00', $row['act_profit']);
        if ($row['est_profit'] !== null) {
            $this->assertSame(bcsub('700.00', $row['est_profit'], 2), $row['variance']);
        } else {
            $this->assertNull($row['variance']);
        }

        $vp = $this->as($finance)->getJson('/api/v1/reports/vessel-profitability')->assertOk()->json('data');
        $this->assertCount(1, $vp['rows']);
        $this->assertSame('1', $vp['rows'][0]['voyages']);
        $this->assertSame('700.00', $vp['rows'][0]['net_profit']);
        $this->assertSame('70.00', $vp['rows'][0]['margin_pct']);
    }

    public function test_operational_reports_return_rows_and_columns(): void
    {
        $id = $this->voyage();
        $finance = $this->userWithRole(UserRole::Finance);

        $status = $this->as($finance)->getJson('/api/v1/reports/voyage-status')->assertOk()->json('data');
        $this->assertCount(1, $status['rows']);
        $this->assertSame('draft', $status['rows'][0]['status']);

        $fleet = $this->as($finance)->getJson('/api/v1/reports/fleet-status')->assertOk()->json('data');
        $this->assertNotEmpty($fleet['rows']);
        $this->assertContains('operational', array_column($fleet['columns'], 'key'));

        $this->as($finance)->getJson('/api/v1/reports/contract-status')->assertOk()->assertJsonStructure(['data' => ['columns', 'rows']]);
        $this->as($finance)->getJson("/api/v1/reports/bunker-consumption?voyage_id={$id}")->assertOk()->assertJsonPath('data.rows', []);
    }

    public function test_bunker_consumption_sums_verified_reports_only(): void
    {
        $id = $this->voyage();
        $fuel = FuelType::query()->firstOrFail();
        foreach (['verified' => ['12.5', '3'], 'draft' => ['99', '99']] as $status => [$consumed, $received]) {
            $report = CaptainReport::query()->create(['vessel_id' => $this->vessel->id, 'voyage_id' => $id, 'report_type' => 'noon', 'reported_at' => '2026-10-15 12:00:00', 'status' => $status]);
            CaptainReportFuelLine::query()->create(['captain_report_id' => $report->id, 'fuel_type_id' => $fuel->id, 'rob_mt' => '100', 'consumed_mt' => $consumed, 'received_mt' => $received]);
        }

        $finance = $this->userWithRole(UserRole::Finance);
        $rows = $this->as($finance)->getJson("/api/v1/reports/bunker-consumption?voyage_id={$id}")->assertOk()->json('data.rows');
        $this->assertCount(1, $rows);
        $this->assertSame('12.500', $rows[0]['consumed']);
        $this->assertSame('3.000', $rows[0]['received']);
        $this->assertSame('1', $rows[0]['reports']);
    }

    public function test_port_cost_laytime_offshore_and_chartering_reports(): void
    {
        $id = $this->sailingVoyage();
        $call = $this->portCall($id, ['eta' => '2026-10-15T06:00']);
        PortDa::query()->create(['da_number' => 'DA-R-1', 'port_call_id' => $call['id'], 'voyage_id' => $id, 'port_id' => $this->ports[0], 'da_type' => 'final',
            'currency' => 'USD', 'total_amount' => '500.00', 'base_amount' => '500.00', 'status' => 'approved']);
        LaytimeCalculation::query()->create(['port_call_id' => $call['id'], 'voyage_id' => $id, 'calculation_type' => 'load', 'status' => 'agreed', 'currency' => 'USD',
            'allowed_hours' => '72', 'used_hours' => '102', 'difference_hours' => '-30', 'demurrage_amount' => '30000.00']);
        OffshoreActivity::query()->create(['activity_number' => 'OA-R-1', 'vessel_id' => $this->vessel->id, 'voyage_id' => $id, 'offshore_activity_type_id' => OffshoreActivityType::query()->value('id'),
            'start_at' => '2026-10-15 06:00:00', 'end_at' => '2026-10-15 14:00:00', 'billable_hours' => '8', 'currency' => 'USD', 'revenue_amount' => '4000.00', 'status' => 'verified']);

        $finance = $this->userWithRole(UserRole::Finance);
        $port = $this->as($finance)->getJson("/api/v1/reports/port-cost?voyage_id={$id}")->assertOk()->json('data.rows');
        $this->assertSame(['DA-R-1', '500.00', 'approved'], [$port[0]['da'], $port[0]['amount'], $port[0]['status']]);

        $lay = $this->as($finance)->getJson("/api/v1/reports/laytime-demurrage?voyage_id={$id}")->assertOk()->json('data.rows');
        $this->assertSame(['72.00', '102.00', '-30.00', '30000.00'], [$lay[0]['allowed'], $lay[0]['used'], $lay[0]['difference'], $lay[0]['demurrage']]);

        // Revenue column follows contracts.rates.view (OA-14): finance sees it, marine operations does not.
        $withRates = $this->as($finance)->getJson('/api/v1/reports/offshore-activities')->assertOk()->json('data');
        $this->assertContains('revenue', array_column($withRates['columns'], 'key'));
        $this->assertSame('4000.00', $withRates['rows'][0]['revenue']);
        $marine = $this->userWithRole(UserRole::MarineOperations);
        $noRates = $this->as($marine)->getJson('/api/v1/reports/offshore-activities')->assertOk()->json('data');
        $this->assertNotContains('revenue', array_column($noRates['columns'], 'key'));
        $this->assertArrayNotHasKey('revenue', $noRates['rows'][0]);
        $this->assertSame('8.00', $noRates['rows'][0]['billable']);

        $this->as($finance)->getJson('/api/v1/reports/chartering-activity')->assertOk()->assertJsonStructure(['data' => ['columns', 'rows']]);
    }

    public function test_excel_export_is_a_valid_package_and_keeps_formulas_as_text(): void
    {
        $id = $this->voyage();
        $this->revenue($id, '250.00', 'confirmed', '0.00', false, '=HYPERLINK("http://x")');
        $finance = $this->userWithRole(UserRole::Finance);

        $response = $this->as($finance)->get('/api/v1/reports/revenue?format=xlsx');
        $response->assertOk();
        $this->assertStringContainsString('spreadsheetml.sheet', (string) $response->headers->get('Content-Type'));

        $path = tempnam(sys_get_temp_dir(), 'xlsxtest');
        file_put_contents($path, $response->streamedContent());
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($path));
        $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $this->assertNotFalse($zip->getFromName('xl/workbook.xml'));
        $zip->close();
        unlink($path);

        $dom = new \DOMDocument;
        $this->assertTrue($dom->loadXML($sheet), 'sheet XML must be well-formed');
        $this->assertStringContainsString('&quot;http://x&quot;', $sheet);            // stored as inline text
        $this->assertStringNotContainsString('<f>', $sheet);                          // never a formula
        $this->assertStringContainsString('<v>250.00</v>', $sheet);                   // amounts are numeric cells

        $readOnly = $this->userWithRole(UserRole::ReadOnly);
        $this->as($readOnly)->get('/api/v1/reports/revenue?format=xlsx')->assertForbidden();
    }

    public function test_vessel_utilization_merges_overlapping_voyages_and_excludes_disputed_off_hire(): void
    {
        $first = $this->sailingVoyage();   // commenced 2026-10-14 04:00Z; test clock 2026-10-20 12:00Z
        $this->sailingVoyage();            // same vessel, same instants: must not be counted twice
        foreach ([['2026-10-15 00:00:00', '2026-10-16 00:00:00', 'agreed'], ['2026-10-17 00:00:00', '2026-10-18 00:00:00', 'disputed']] as [$from, $to, $status]) {
            OffHireEvent::query()->create(['voyage_id' => $first, 'from_at' => $from, 'to_at' => $to, 'reason_code' => 'breakdown', 'hours' => '24', 'status' => $status]);
        }

        $finance = $this->userWithRole(UserRole::Finance);
        $rows = $this->as($finance)->getJson('/api/v1/reports/vessel-utilization?from=2026-10-10&to=2026-10-20')->assertOk()->json('data.rows');
        $row = collect($rows)->firstWhere('vessel', $this->vessel->name);

        $this->assertSame('11.00', $row['period_days']);
        $this->assertSame('6.33', $row['voyage_days']);         // 14 Oct 04:00 → 20 Oct 12:00, once
        $this->assertSame('1.00', $row['off_hire_days']);       // the disputed day is excluded
        $this->assertSame('48.48', $row['utilization_pct']);    // (6d8h − 1d) / (11d − 1s)
        $this->assertSame('2', $row['voyages']);

        $this->as($finance)->getJson('/api/v1/reports/vessel-utilization?from=2024-01-01&to=2026-10-20')->assertStatus(422);
    }

    public function test_vessel_performance_uses_verified_reports_only(): void
    {
        $id = $this->voyage();
        foreach ([['verified', '240', '12'], ['verified', '120', '10'], ['draft', '999', '30']] as $i => [$status, $distance, $speed]) {
            $report = CaptainReport::query()->create(['vessel_id' => $this->vessel->id, 'voyage_id' => $id, 'report_type' => 'noon', 'reported_at' => '2026-10-1'.(5 + $i).' 12:00:00',
                'status' => $status, 'distance_since_last_nm' => $distance, 'speed_kn' => $speed]);
            CaptainReportFuelLine::query()->create(['captain_report_id' => $report->id, 'fuel_type_id' => FuelType::query()->value('id'), 'rob_mt' => '100', 'consumed_mt' => '24', 'received_mt' => '0']);
        }

        $finance = $this->userWithRole(UserRole::Finance);
        $row = $this->as($finance)->getJson('/api/v1/reports/vessel-performance')->assertOk()->json('data.rows.0');

        $this->assertSame('2', $row['reports']);
        $this->assertSame('360.00', $row['distance_nm']);
        $this->assertSame('11.00', $row['avg_speed_kn']);
        $this->assertSame('48.000', $row['fuel_mt']);
        $this->assertSame('0.1333', $row['mt_per_nm']);
        $this->assertSame('12.00', $row['service_speed_kn']);
    }

    public function test_offshore_hour_statistics_and_metric_catalogue_follow_permissions(): void
    {
        $id = $this->voyage();
        foreach ([['verified', '8', '2'], ['draft', '5', '5']] as $i => [$status, $billable, $standby]) {
            OffshoreActivity::query()->create(['activity_number' => "OA-S-{$i}", 'vessel_id' => $this->vessel->id, 'voyage_id' => $id, 'offshore_activity_type_id' => OffshoreActivityType::query()->value('id'),
                'start_at' => '2026-10-15 06:00:00', 'end_at' => '2026-10-15 16:00:00', 'billable_hours' => $billable, 'standby_hours' => $standby, 'status' => $status]);
        }

        $finance = $this->userWithRole(UserRole::Finance);
        $billable = $this->as($finance)->getJson('/api/v1/statistics/billable-hours?group_by=vessel')->assertOk()->json('data');
        $this->assertSame('hours', $billable['unit']);
        $this->assertSame('8.00', $billable['total']);
        $this->assertSame($this->vessel->name, $billable['rows'][0]['label']);
        $this->assertSame('2.00', $this->as($finance)->getJson('/api/v1/statistics/standby-hours?group_by=activity_type')->json('data.total'));

        $this->assertEqualsCanonicalizing(['revenue', 'expenses', 'avg-voyage-profit', 'billable-hours', 'standby-hours'], array_column($this->as($finance)->getJson('/api/v1/statistics')->json('data'), 'metric'));
        // Operations may see operational metrics but not money.
        $this->assertEqualsCanonicalizing(['billable-hours', 'standby-hours'], array_column($this->as($this->ops)->getJson('/api/v1/statistics')->json('data'), 'metric'));
        $this->as($this->ops)->getJson('/api/v1/statistics/billable-hours')->assertOk();
        $this->as($this->ops)->getJson('/api/v1/statistics/avg-voyage-profit')->assertForbidden();
    }

    public function test_average_voyage_profit_ignores_voyages_without_ledger_lines(): void
    {
        $withLedger = $this->completedVoyage();
        $this->revenue($withLedger, '1000.00', 'confirmed');
        $this->expense($withLedger, '300.00', 'approved');
        $this->completedVoyage();   // completed but nothing booked yet

        $finance = $this->userWithRole(UserRole::Finance);
        $stat = $this->as($finance)->getJson('/api/v1/statistics/avg-voyage-profit?group_by=vessel')->assertOk()->json('data');

        $this->assertSame('700.00', $stat['total']);
        $this->assertSame(1, $stat['rows'][0]['line_count']);
    }

    /** @return int a completed voyage */
    private function completedVoyage(): int
    {
        $id = $this->sailingVoyage();
        $call = $this->portCall($id, ['eta' => '2026-10-15T06:00']);
        $this->putJson("/api/v1/voyages/{$id}/port-calls/{$call['id']}", ['lock_version' => $call['lock_version'], 'ata' => '2026-10-15T08:00', 'atd' => '2026-10-16T20:00'])->assertOk();
        $this->postJson("/api/v1/voyages/{$id}/complete", ['at' => '2026-10-19T10:00'])->assertOk();

        return $id;
    }

    public function test_statistics_group_and_reject_unsupported_grouping(): void
    {
        $id = $this->voyage();
        $this->revenue($id, '1000.00', 'confirmed');
        $this->revenue($id, '200.00', 'invoiced');
        $this->expense($id, '300.00', 'paid');

        $finance = $this->userWithRole(UserRole::Finance);
        $byCat = $this->as($finance)->getJson('/api/v1/statistics/revenue?group_by=category')->assertOk()->json('data');
        $this->assertSame('1200.00', $byCat['total']);
        $this->assertSame('Freight', $byCat['rows'][0]['label']);
        $this->assertSame(2, $byCat['rows'][0]['line_count']);

        $byMonth = $this->as($finance)->getJson('/api/v1/statistics/expenses?group_by=month')->assertOk()->json('data');
        $this->assertSame('300.00', $byMonth['total']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}$/', $byMonth['rows'][0]['label']);

        $this->as($finance)->getJson('/api/v1/statistics/expenses?group_by=customer')->assertStatus(422);
        $this->as($this->ops)->getJson('/api/v1/statistics/revenue')->assertForbidden();
    }

    public function test_dashboard_hides_money_without_financials_permission(): void
    {
        $this->voyage();
        $finance = $this->userWithRole(UserRole::Finance);
        $full = $this->as($finance)->getJson('/api/v1/dashboard')->assertOk()->json('data');
        $this->assertArrayHasKey('outstanding_invoices', $full);
        $this->assertArrayHasKey('open_payables', $full);
        $this->assertArrayHasKey('invoices', $full['pending_approvals']);
        $this->assertArrayNotHasKey('estimations', $full['pending_approvals']);

        $ops = $this->as($this->ops)->getJson('/api/v1/dashboard')->assertOk()->json('data');
        $this->assertArrayNotHasKey('outstanding_invoices', $ops);
        $this->assertArrayHasKey('active_vessels', $ops);
        $this->assertSame([], $ops['pending_approvals']);
    }

    public function test_large_reports_are_cut_on_screen_but_refused_as_files(): void
    {
        $id = $this->voyage();
        foreach (range(1, 5) as $n) {
            $this->revenue($id, "{$n}00.00", 'confirmed', '0.00', false, "Line {$n}");
        }
        $finance = $this->userWithRole(UserRole::Finance);
        config(['offshore.reports.view_limit' => 3, 'offshore.reports.export_limit' => 3, 'offshore.reports.pdf_limit' => 3]);

        $screen = $this->as($finance)->getJson('/api/v1/reports/revenue')->assertOk()->json('data');
        $this->assertCount(3, $screen['rows']);
        $this->assertTrue($screen['truncated']);
        $this->assertSame(3, $screen['row_limit']);

        foreach (['csv', 'xlsx', 'pdf'] as $format) {
            $this->as($finance)->getJson("/api/v1/reports/revenue?format={$format}")->assertStatus(422)->assertJsonPath('error_code', 'report_too_large');
        }
        // Under the limit, a filtered export is fine and the screen reports no truncation.
        config(['offshore.reports.view_limit' => 10, 'offshore.reports.export_limit' => 10]);
        $this->assertFalse($this->as($finance)->getJson('/api/v1/reports/revenue')->json('data.truncated'));
        $this->as($finance)->get('/api/v1/reports/revenue?format=csv')->assertOk();
    }

    public function test_dashboard_money_figures_count_only_open_documents_and_split_overdue(): void
    {
        $this->voyage();
        $customer = Company::query()->create(['code' => 'DB1', 'legal_name' => 'Dash Co', 'normalized_name' => 'dash co']);
        $invoice = fn (string $status, string $balanceTotal, string $paid, int $dueInDays, string $fx = '1') => Invoice::query()->create([
            'invoice_type' => 'freight', 'customer_company_id' => $customer->id, 'billing_snapshot' => ['name' => 'x'], 'issue_date' => now()->toDateString(), 'due_date' => now()->addDays($dueInDays)->toDateString(),
            'currency' => 'USD', 'fx_rate' => $fx, 'subtotal' => $balanceTotal, 'tax_amount' => '0.00', 'total' => $balanceTotal, 'base_total' => $balanceTotal, 'amount_paid' => $paid, 'status' => $status,
        ]);
        $invoice('issued', '100.00', '0.00', -5);            // open, overdue        → 100.00
        $invoice('partially_paid', '200.00', '150.00', 10);  // open, not yet due    → 50.00 balance
        $invoice('issued', '300.00', '0.00', 3, '0.5');      // open, FX 0.5         → 150.00 base
        $invoice('paid', '400.00', '400.00', -30);           // settled: excluded
        $invoice('draft', '500.00', '0.00', -30);            // not issued: excluded
        Payable::query()->create(['payable_number' => 'PAYB-D1', 'supplier_company_id' => $customer->id, 'supplier_invoice_ref' => 'D1', 'issue_date' => now()->toDateString(),
            'due_date' => now()->subDay()->toDateString(), 'currency' => 'USD', 'fx_rate' => '1', 'subtotal' => '40.00', 'tax' => '0.00', 'total' => '40.00', 'base_total' => '40.00', 'status' => 'approved']);
        Payable::query()->create(['payable_number' => 'PAYB-D2', 'supplier_company_id' => $customer->id, 'supplier_invoice_ref' => 'D2', 'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(5)->toDateString(), 'currency' => 'USD', 'fx_rate' => '1', 'subtotal' => '99.00', 'tax' => '0.00', 'total' => '99.00', 'base_total' => '99.00', 'status' => 'draft']);

        $data = $this->as($this->userWithRole(UserRole::Finance))->getJson('/api/v1/dashboard')->assertOk()->json('data');

        $this->assertSame(['count' => 3, 'base_total' => '300.00', 'overdue_count' => 1, 'overdue_base_total' => '100.00'], $data['outstanding_invoices']);
        $this->assertSame(['count' => 1, 'base_total' => '40.00', 'overdue_count' => 1], $data['open_payables']);
    }
}
