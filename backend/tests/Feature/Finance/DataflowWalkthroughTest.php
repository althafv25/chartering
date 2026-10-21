<?php

namespace Tests\Feature\Finance;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\DaCostCategory;
use App\Models\ExpenseCategory;
use App\Models\FuelType;
use App\Models\RevenueCategory;
use App\Models\Vessel;
use App\Models\VesselType;
use Illuminate\Support\Carbon;
use Tests\Feature\Operations\OperationsTestCase;

/**
 * Replays docs/dataflow.md end to end through the API with the exact dummy data from that document and pins every
 * headline figure. If a calculation or workflow rule changes, this test and the document must change together.
 */
class DataflowWalkthroughTest extends OperationsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-10 12:00:00');
    }

    public function test_charter_from_enquiry_to_final_profit(): void
    {
        $hfo = FuelType::query()->where('code', 'HFO')->value('id');
        $gulf = Company::query()->create(['code' => 'GTF', 'legal_name' => 'Gulf Trading FZE', 'normalized_name' => 'gulf trading']);
        $gulf->roles()->create(['role' => 'charterer']);
        $oceanic = Company::query()->create(['code' => 'OFT', 'legal_name' => 'Oceanic Fuels Trading LLC', 'normalized_name' => 'oceanic fuels trading']);
        $oceanic->roles()->create(['role' => 'supplier']);

        $vessel = Vessel::query()->create(['code' => 'EXT01', 'name' => 'MV Example Trader', 'vessel_type_id' => VesselType::query()->value('id'), 'service_speed_kn' => '12']);
        $profile = $vessel->consumptionProfiles()->create(['name' => 'Design', 'effective_from' => '2026-01-01', 'is_default' => true]);
        $profile->rates()->create(['mode' => 'sea_laden', 'speed_kn' => '12', 'fuel_type_id' => $hfo, 'consumption_mt_per_day' => '20']);
        $profile->rates()->create(['mode' => 'port_working', 'speed_kn' => '0', 'fuel_type_id' => $hfo, 'consumption_mt_per_day' => '5']);

        // ---- 1. Enquiry
        $enq = $this->enquiry([
            'charterer_company_id' => $gulf->id, 'cargo_description' => 'General cargo', 'quantity' => '2000', 'quantity_unit' => 'mt',
            'rate_idea' => '55', 'rate_basis' => 'per_mt', 'currency' => 'USD', 'laycan_from' => '2026-10-01', 'laycan_to' => '2026-10-05',
        ]);

        // ---- 2. Estimation
        $res = $this->as($this->charterer)->postJson('/api/v1/estimations', ['enquiry_id' => $enq, 'vessel_id' => $vessel->id])->assertCreated();
        $est = $res->json('data.id');
        $sc = $res->json('data.scenarios.0.id');
        $s = $this->getJson("/api/v1/estimations/{$est}/scenarios/{$sc}")->json('data');
        $in = $s['inputs'];
        $in['fuel_prices'][0]['price_per_mt'] = '600';
        $in['calls'][0]['working_days'] = '2';
        $in['calls'][0]['port_cost']['amount'] = '4500';
        $in['calls'][0]['agency_cost']['amount'] = '600';
        $in['calls'][1]['working_days'] = '1.5';
        $in['calls'][1]['port_cost']['amount'] = '3500';
        $in['calls'][1]['agency_cost']['amount'] = '600';
        $in['revenue_items'][0]['address_pct'] = '2.5';
        $in['revenue_items'][0]['brokerage_pct'] = '2.5';
        $saved = $this->putJson("/api/v1/estimations/{$est}/scenarios/{$sc}", ['lock_version' => $s['lock_version'], 'inputs' => $in])->assertOk()->json('data');
        $this->assertSame('calculated', $saved['calc_status']);
        $r = $saved['result'];
        $this->assertSame(['330.00', '1.145833', '3.500000', '4.645833'], [$r['sea_distance_nm'], $r['sea_days'], $r['port_days'], $r['total_days']]);
        $this->assertSame(['40.417', '24250.00', '8000.00', '1200.00', '33450.00'], [$r['fuel_total_mt'], $r['fuel_cost'], $r['port_costs'], $r['agency_costs'], $r['voyage_costs']]);
        $this->assertSame(['110000.00', '5500.00', '104500.00', '71050.00', '15293.27', '17.6053'], [$r['gross_revenue'], $r['total_commission'], $r['net_revenue'], $r['profit'], $r['tce_per_day'], $r['breakeven_rate']]);
        $this->postJson("/api/v1/estimations/{$est}/scenarios/{$sc}/select")->assertOk();
        $this->postJson("/api/v1/estimations/{$est}/submit")->assertOk();
        $this->as($this->manager)->postJson("/api/v1/estimations/{$est}/approve")->assertOk();

        // ---- 3. Offer
        $this->as($this->charterer);
        $offer = $this->postJson('/api/v1/offers', ['enquiry_id' => $enq, 'vessel_id' => $vessel->id, 'estimation_scenario_id' => $sc])->assertCreated()->json('data');
        $rev = $offer['revisions'][0]['id'];
        $this->putJson("/api/v1/offers/{$offer['id']}/revisions/{$rev}", ['valid_until' => '2026-10-03', 'terms' => 'Subject to charter party. Freight payable within 30 days of discharge.'])->assertOk();
        $this->postJson("/api/v1/offers/{$offer['id']}/revisions/{$rev}/send")->assertOk();
        $this->postJson("/api/v1/offers/{$offer['id']}/revisions/{$rev}/accept", ['note' => 'Accepted by email'])->assertOk();

        // ---- 4. Fixture + contract
        $fx = $this->postJson("/api/v1/offers/{$offer['id']}/revisions/{$rev}/convert-to-fixture")->assertCreated()->json('data');
        $this->postJson("/api/v1/fixtures/{$fx['id']}/submit")->assertOk();
        $this->as($this->manager)->postJson("/api/v1/fixtures/{$fx['id']}/approve")->assertOk();
        $this->as($this->charterer);
        $contract = $this->postJson("/api/v1/fixtures/{$fx['id']}/convert-to-contract")->assertCreated()->json('data');
        $this->putJson("/api/v1/contracts/{$contract['id']}", ['lock_version' => $contract['lock_version'], 'title' => 'Gulf Trading — MV Example Trader', 'start_date' => '2026-10-01', 'end_date' => '2026-12-31',
            'payment_terms_days' => 30, 'payment_terms_text' => 'Freight payable within 30 days of discharge'])->assertOk();
        $this->postJson("/api/v1/contracts/{$contract['id']}/submit")->assertOk();
        $this->as($this->commercial)->postJson("/api/v1/contracts/{$contract['id']}/approve")->assertOk();
        $this->as($this->charterer)->postJson("/api/v1/contracts/{$contract['id']}/activate")->assertOk();

        // ---- 5. Voyage
        $ops2 = $this->userWithRole(UserRole::Operations);
        $this->as($this->ops);
        $voyage = $this->postJson("/api/v1/fixtures/{$fx['id']}/convert-to-voyage")->assertCreated()->json('data');
        $v = $voyage['id'];
        $this->assertSame($contract['id'], $voyage['contract_id']);
        $move = fn (string $to, string $at) => $this->postJson("/api/v1/voyages/{$v}/transition", ['status' => $to, 'at' => $at])->assertOk();
        $move('nominated', '2026-09-30T10:00');
        $move('loading', '2026-10-01T09:00');

        $ja = $this->postJson("/api/v1/voyages/{$v}/port-calls", ['port_id' => $this->ports[0], 'purpose' => 'load', 'eta' => '2026-10-01T06:00',
            'ata' => '2026-10-01T06:00', 'atb' => '2026-10-01T09:00'])->assertCreated()->json('data');
        $rt = $this->postJson("/api/v1/voyages/{$v}/port-calls", ['port_id' => $this->ports[1], 'purpose' => 'discharge', 'eta' => '2026-10-04T12:00'])->assertCreated()->json('data');

        // Bunkers at Jebel Ali
        $stem = $this->postJson('/api/v1/bunker-stems', ['vessel_id' => $vessel->id, 'voyage_id' => $v, 'port_call_id' => $ja['id'], 'fuel_type_id' => $hfo,
            'supplier_company_id' => $oceanic->id, 'ordered_on' => '2026-09-30', 'ordered_mt' => '44', 'price_per_mt' => '600', 'currency' => 'USD'])->assertCreated()->json('data');
        $stem = $this->postJson("/api/v1/bunker-stems/{$stem['id']}/deliver", ['lock_version' => $stem['lock_version'], 'delivered_at' => '2026-10-02T10:00', 'delivered_mt' => '44', 'bdn_number' => 'BDN-0001'])
            ->assertOk()->json('data');
        $this->assertSame('26400.00', $stem['total_amount']);

        $ja = $this->putJson("/api/v1/voyages/{$v}/port-calls/{$ja['id']}", ['lock_version' => $ja['lock_version'], 'atd' => '2026-10-03T10:00'])->assertOk()->json('data');
        $move('loaded', '2026-10-03T09:00');
        $move('sailing', '2026-10-03T10:00');

        // Arrival report applies ATA at Ras Tanura
        $report = $this->postJson('/api/v1/captain-reports', ['vessel_id' => $vessel->id, 'voyage_id' => $v, 'port_call_id' => $rt['id'], 'report_type' => 'arrival',
            'reported_at' => '2026-10-04T12:30:00+03:00', 'distance_since_last_nm' => '330', 'fuel_lines' => [['fuel_type_id' => $hfo, 'rob_mt' => '77', 'consumed_mt' => '23', 'received_mt' => '0']]])
            ->assertCreated()->json('data');
        $this->postJson("/api/v1/captain-reports/{$report['id']}/submit")->assertOk();
        $this->as($ops2)->postJson("/api/v1/captain-reports/{$report['id']}/verify", ['apply_to_port_call' => true])->assertOk();
        $this->as($this->ops);
        $move('discharging', '2026-10-04T14:00');
        $rt = $this->getJson("/api/v1/voyages/{$v}")->json('data.port_calls.1');
        $this->assertNotNull($rt['ata']);
        $rt = $this->putJson("/api/v1/voyages/{$v}/port-calls/{$rt['id']}", ['lock_version' => $rt['lock_version'], 'atb' => '2026-10-04T14:00', 'atd' => '2026-10-07T06:00'])->assertOk()->json('data');

        // Port DAs (final)
        $cat = fn (string $code) => DaCostCategory::query()->where('code', $code)->value('id');
        $mkDa = function (array $call, int $portId, array $items) use ($v, $ops2) {
            $da = $this->postJson('/api/v1/port-das', ['voyage_id' => $v, 'port_call_id' => $call['id'], 'port_id' => $portId, 'da_type' => 'final', 'currency' => 'USD'])->assertCreated()->json('data');
            $this->postJson("/api/v1/port-das/{$da['id']}/items", ['items' => $items])->assertOk();
            $this->postJson("/api/v1/port-das/{$da['id']}/submit")->assertOk();
            $this->as($ops2)->postJson("/api/v1/port-das/{$da['id']}/approve")->assertOk();
            $this->as($this->ops);

            return $da['id'];
        };
        $mkDa($ja, $this->ports[0], [
            ['da_cost_category_id' => $cat('PILOT'), 'description' => 'Pilotage', 'estimated_amount' => '1500', 'actual_amount' => '1800'],
            ['da_cost_category_id' => $cat('BERTH'), 'description' => 'Berth / dockage', 'estimated_amount' => '2500', 'actual_amount' => '2500'],
            ['da_cost_category_id' => $cat('AGENCY'), 'description' => 'Agency fee', 'estimated_amount' => '600', 'actual_amount' => '600'],
        ]);
        $mkDa($rt, $this->ports[1], [
            ['da_cost_category_id' => $cat('PILOT'), 'description' => 'Pilotage', 'estimated_amount' => '1800', 'actual_amount' => '2000'],
            ['da_cost_category_id' => $cat('TOWAGE'), 'description' => 'Towage', 'estimated_amount' => '1000', 'actual_amount' => '1200'],
            ['da_cost_category_id' => $cat('BERTH'), 'description' => 'Berth / dockage', 'estimated_amount' => '1700', 'actual_amount' => '1600'],
            ['da_cost_category_id' => $cat('AGENCY'), 'description' => 'Agency fee', 'estimated_amount' => '600', 'actual_amount' => '600'],
        ]);

        // Laytime at the discharge port
        $lt = $this->postJson('/api/v1/laytime-calculations', ['voyage_id' => $v, 'port_call_id' => $rt['id'], 'calculation_type' => 'discharge', 'fixed_hours' => '48',
            'demurrage_rate_per_day' => '12000', 'despatch_rate_per_day' => '6000', 'currency' => 'USD', 'once_on_demurrage_rule' => 'always_on_demurrage',
            'nor_tendered_at' => '2026-10-04T12:45', 'nor_accepted_at' => '2026-10-04T13:00', 'laytime_commenced_at' => '2026-10-04T16:00', 'laytime_completed_at' => '2026-10-07T04:00'])
            ->assertCreated()->json('data');
        $lurl = "/api/v1/laytime-calculations/{$lt['id']}";
        foreach ([['ARRIVED', '2026-10-04T12:30', 'Arrived Ras Tanura anchorage'], ['NOR_TENDERED', '2026-10-04T12:45', 'NOR tendered'], ['ALL_FAST', '2026-10-04T14:00', 'All fast'],
            ['COMMENCED_DISCHARGING', '2026-10-04T16:00', 'Commenced discharging'], ['COMPLETED_DISCHARGING', '2026-10-07T04:00', 'Completed discharging'], ['SAILED', '2026-10-07T06:00', 'Sailed']] as [$code, $at, $d]) {
            $this->postJson("{$lurl}/sof-events", ['event_code' => $code, 'event_at' => $at, 'description' => $d])->assertCreated();
        }
        $this->postJson("{$lurl}/exceptions", ['from_at' => '2026-10-05T02:00', 'to_at' => '2026-10-05T06:00', 'exception_type' => 'weather', 'pct_counted' => '0', 'remarks' => 'Rain'])->assertCreated();
        $calc = $this->postJson("{$lurl}/calculate")->assertOk()->json('data');
        $this->assertSame(['48.0000', '56.0000', '-8.0000', '4000.00'], [$calc['allowed_hours'], $calc['used_hours'], $calc['difference_hours'], $calc['demurrage_amount']]);
        $this->postJson("{$lurl}/submit")->assertOk();
        $this->as($ops2)->postJson("{$lurl}/agree")->assertOk();
        $this->as($this->ops);

        $this->postJson("/api/v1/voyages/{$v}/complete", ['at' => '2026-10-07T08:00'])->assertOk();

        // ---- 6. Finance
        $finance = $this->userWithRole(UserRole::Finance);
        $this->as($finance);
        $freight = $this->postJson('/api/v1/voyage-revenues', ['voyage_id' => $v, 'contract_id' => $contract['id'], 'revenue_category_id' => RevenueCategory::query()->where('code', 'FREIGHT')->value('id'),
            'description' => 'Freight — Jebel Ali to Ras Tanura', 'quantity' => '2000', 'rate' => '55', 'currency' => 'USD', 'commission_pct_total' => '5'])->assertCreated()->json('data');
        $this->postJson("/api/v1/voyage-revenues/{$freight['id']}/confirm")->assertOk();
        $bunker = $this->postJson('/api/v1/voyage-expenses', ['voyage_id' => $v, 'contract_id' => $contract['id'], 'expense_category_id' => ExpenseCategory::query()->where('code', 'BUNKER')->value('id'),
            'description' => 'Bunkers — 44 MT HFO @ 600 (BDN-0001)', 'quantity' => '44', 'rate' => '600', 'currency' => 'USD', 'supplier_company_id' => $oceanic->id, 'incurred_at' => '2026-10-02'])
            ->assertCreated()->json('data');
        $this->postJson("/api/v1/voyage-expenses/{$bunker['id']}/confirm")->assertOk();
        $this->as($this->commercial)->postJson("/api/v1/voyage-expenses/{$bunker['id']}/approve")->assertOk();

        $this->as($finance);
        $exp = $this->getJson("/api/v1/voyage-expenses?voyage_id={$v}&per_page=50")->json('data');
        $rev = $this->getJson("/api/v1/voyage-revenues?voyage_id={$v}&per_page=50")->json('data');
        foreach ($exp as $e) {
            if ($e['status'] === 'confirmed') {
                $this->as($this->commercial)->postJson("/api/v1/voyage-expenses/{$e['id']}/approve")->assertOk();
            }
        }
        $this->as($finance);

        $revIds = collect($rev)->pluck('id')->all();
        $inv = $this->postJson('/api/v1/invoices', ['invoice_type' => 'freight', 'customer_company_id' => $gulf->id, 'contract_id' => $contract['id'], 'voyage_id' => $v,
            'issue_date' => '2026-10-08', 'due_date' => '2026-11-07', 'currency' => 'USD', 'revenue_ids' => $revIds])->assertCreated()->json('data');
        $inv = $this->postJson("/api/v1/invoices/{$inv['id']}/issue")->assertOk()->json('data');
        $this->assertSame(['INV-2026-00001', '114000.00', 'issued'], [$inv['invoice_number'], $inv['total'], $inv['status']]);

        $pay = $this->postJson('/api/v1/payments', ['direction' => 'received', 'currency' => 'USD', 'company_id' => $gulf->id, 'payment_date' => '2026-10-09', 'amount' => '114000',
            'method' => 'wire', 'bank_reference' => 'TT-998877'])->assertCreated()->json('data');
        $this->postJson("/api/v1/payments/{$pay['id']}/allocate", ['allocations' => [['invoice_id' => $inv['id'], 'amount' => '114000']]])->assertOk();
        $this->assertSame('paid', $this->getJson("/api/v1/invoices/{$inv['id']}")->json('data.status'));

        $payable = $this->postJson('/api/v1/payables', ['supplier_company_id' => $oceanic->id, 'supplier_invoice_ref' => 'OF-INV-7788', 'currency' => 'USD',
            'issue_date' => '2026-10-02', 'due_date' => '2026-10-30', 'subtotal' => '26400', 'tax' => '0'])->assertCreated()->json('data');
        $this->as($this->commercial)->postJson("/api/v1/payables/{$payable['id']}/approve")->assertOk();
        $this->as($finance);
        $out = $this->postJson('/api/v1/payments', ['direction' => 'paid', 'currency' => 'USD', 'company_id' => $oceanic->id, 'payment_date' => '2026-10-09', 'amount' => '26400',
            'method' => 'wire', 'bank_reference' => 'TT-OUT-5521'])->assertCreated()->json('data');
        $this->postJson("/api/v1/payments/{$out['id']}/allocate", ['allocations' => [['payable_id' => $payable['id'], 'amount' => '26400']]])->assertOk();
        $this->assertSame('paid', $this->getJson("/api/v1/payables/{$payable['id']}")->json('data.status'));

        // ---- 7. Finalize + P&L
        $gates = $this->as($this->ops)->getJson("/api/v1/voyages/{$v}/finance-gates")->json('data');
        $this->assertSame(['ledger' => true, 'revenue' => true, 'port_da' => true, 'laytime' => true], array_map(fn ($g) => $g['passed'], $gates));
        $final = $this->postJson("/api/v1/voyages/{$v}/finalize")->assertOk()->json('data');
        $this->assertSame('finalized', $final['status']);

        $pnl = $this->as($finance)->getJson("/api/v1/voyages/{$v}/financials")->assertOk()->json('data');
        $rows = collect($pnl['rows'])->keyBy('metric');
        $this->assertSame(['110000.00', '114000.00', '4000.00'], [$rows['gross_revenue']['estimate'], $rows['gross_revenue']['actual'], $rows['gross_revenue']['variance']]);
        $this->assertSame(['5500.00', '5500.00'], [$rows['commission']['estimate'], $rows['commission']['actual']]);
        $this->assertSame(['33450.00', '36700.00', '3250.00', '9.72'], [$rows['total_costs']['estimate'], $rows['total_costs']['actual'], $rows['total_costs']['variance'], $rows['total_costs']['variance_pct']]);
        $this->assertSame(['71050.00', '71800.00', '750.00', '1.06'], [$rows['net_profit']['estimate'], $rows['net_profit']['actual'], $rows['net_profit']['variance'], $rows['net_profit']['variance_pct']]);
        $this->assertSame(['77300.00', '62.98', '12046.98'], [$pnl['gross_profit'], $pnl['margin_pct'], $pnl['profit_per_day']]);
        // Every Port DA item must reach the ledger: pilotage + berth + towage = 9,100.00 of port charges, 1,200.00 agency.
        $this->assertSame(['Bunkers' => '26400.00', 'Port charges' => '9100.00', 'Agency fees' => '1200.00'], collect($pnl['expense_by_category'])->pluck('amount', 'category')->all());
        $this->assertSame([], $this->getJson('/api/v1/receivables/aging')->json('data.customers'));
    }
}
