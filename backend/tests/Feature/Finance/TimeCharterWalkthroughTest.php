<?php

namespace Tests\Feature\Finance;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\ExpenseCategory;
use App\Models\FuelType;
use App\Models\RevenueCategory;
use App\Models\Vessel;
use App\Models\VesselType;
use Illuminate\Support\Carbon;
use Tests\Feature\Operations\OperationsTestCase;

/**
 * Replays docs/time-charter-dataflow.md (a trip time charter: hire per day, charterer pays fuel and port costs) end to end
 * through the API with the exact dummy data from that document and pins every headline figure.
 * If a calculation or workflow rule changes, this test and the document change together.
 */
class TimeCharterWalkthroughTest extends OperationsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-10 12:00:00');
    }

    public function test_time_charter_from_enquiry_to_final_profit(): void
    {
        $hfo = FuelType::query()->where('code', 'HFO')->value('id');
        $delta = Company::query()->create(['code' => 'DCF', 'legal_name' => 'Delta Commodities FZE', 'normalized_name' => 'delta commodities']);
        $delta->roles()->create(['role' => 'charterer']);

        // 13.75 kn x 24 h = 330 NM per day, so the 330 NM sea leg takes exactly 1 day.
        $vessel = Vessel::query()->create(['code' => 'TCV01', 'name' => 'MV Time Voyager', 'vessel_type_id' => VesselType::query()->value('id'), 'service_speed_kn' => '13.75']);
        $profile = $vessel->consumptionProfiles()->create(['name' => 'Design', 'effective_from' => '2026-01-01', 'is_default' => true]);
        $profile->rates()->create(['mode' => 'sea_laden', 'speed_kn' => '13.75', 'fuel_type_id' => $hfo, 'consumption_mt_per_day' => '20']);
        $profile->rates()->create(['mode' => 'port_working', 'speed_kn' => '0', 'fuel_type_id' => $hfo, 'consumption_mt_per_day' => '5']);

        // ---- 1. Enquiry (time charter, hire per day)
        $this->as($this->charterer);
        $enq = $this->postJson('/api/v1/enquiries', [
            'business_type' => 'time_charter', 'source' => 'direct', 'charterer_company_id' => $delta->id, 'cargo_description' => 'Trip time charter, general cargo',
            'period_days' => '6', 'rate_idea' => '12000', 'rate_basis' => 'per_day', 'currency' => 'USD', 'laycan_from' => '2026-10-01', 'laycan_to' => '2026-10-02',
            'commission_terms' => '2.5% address + 1.25% brokerage',
            'ports' => [['port_id' => $this->ports[0], 'purpose' => 'delivery'], ['port_id' => $this->ports[1], 'purpose' => 'redelivery']],
        ])->assertCreated()->json('data.id');

        // ---- 2. Estimation
        $res = $this->postJson('/api/v1/estimations', ['enquiry_id' => $enq, 'vessel_id' => $vessel->id])->assertCreated();
        $est = $res->json('data.id');
        $this->assertSame('time_charter', $res->json('data.estimation_type'));
        $sc = $res->json('data.scenarios.0.id');
        $s = $this->getJson("/api/v1/estimations/{$est}/scenarios/{$sc}")->json('data');
        $in = $s['inputs'];

        // What the system pre-fills for a time charter.
        $this->assertSame('ballast', $in['legs'][0]['condition']); // a delivery to redelivery leg defaults to ballast
        $this->assertEquals(13.75, $in['legs'][0]['speed_kn']);
        $this->assertSame('charterer', $in['fuel_prices'][0]['account']);
        $this->assertSame(['charterer', 'charterer'], [$in['calls'][0]['port_cost']['account'], $in['calls'][1]['agency_cost']['account']]);
        $this->assertSame(['HIRE', 'per_day', null], [$in['revenue_items'][0]['category_code'], $in['revenue_items'][0]['basis'], $in['revenue_items'][0]['quantity']]);
        $this->assertEquals(12000, $in['revenue_items'][0]['rate']);
        $this->assertSame([], $in['cost_items']);

        $in['legs'][0]['condition'] = 'laden';
        $in['fuel_prices'][0]['price_per_mt'] = '600';
        $in['calls'][0]['working_days'] = '2';
        $in['calls'][0]['port_cost']['amount'] = '4500';
        $in['calls'][0]['agency_cost']['amount'] = '600';
        $in['calls'][1]['working_days'] = '3';
        $in['calls'][1]['port_cost']['amount'] = '3500';
        $in['calls'][1]['agency_cost']['amount'] = '600';
        $in['revenue_items'][0]['address_pct'] = '2.5';
        $in['revenue_items'][0]['brokerage_pct'] = '1.25';
        $in['cost_items'][] = ['expense_category_id' => ExpenseCategory::query()->where('code', 'OPEX')->value('id'), 'description' => 'Operational expense', 'basis' => 'per_day',
            'quantity' => null, 'rate' => '3000', 'currency' => 'USD', 'fx_rate' => '1', 'account' => 'owner'];
        $saved = $this->putJson("/api/v1/estimations/{$est}/scenarios/{$sc}", ['lock_version' => $s['lock_version'], 'inputs' => $in])->assertOk()->json('data');
        $this->assertSame('calculated', $saved['calc_status']);
        $r = $saved['result'];
        $this->assertSame(['330.00', '1.000000', '5.000000', '6.000000'], [$r['sea_distance_nm'], $r['sea_days'], $r['port_days'], $r['total_days']]);
        // The charterer pays fuel and port costs, so none of them is an owner cost.
        $this->assertSame(['45.000', '0.00', '0.00', '0.00', '0.00'], [$r['fuel_total_mt'], $r['fuel_cost'], $r['port_costs'], $r['agency_costs'], $r['voyage_costs']]);
        $this->assertSame(['18000.00', '18000.00'], [$r['operational_costs'], $r['total_costs']]);
        $this->assertSame(['27000.00', '8000.00', '1200.00'], [$r['breakdown']['charterer_account']['fuel'], $r['breakdown']['charterer_account']['port'], $r['breakdown']['charterer_account']['agency']]);
        $this->assertSame(['72000.00', '2700.00', '69300.00', '51300.00', '11550.00'], [$r['gross_revenue'], $r['total_commission'], $r['net_revenue'], $r['profit'], $r['tce_per_day']]);
        $this->assertSame('3116.8831', $r['breakeven_rate']);
        $this->assertSame(['71.2500', '8550.00'], [$r['profit_margin_pct'], $r['profit_per_day']]);
        $this->postJson("/api/v1/estimations/{$est}/scenarios/{$sc}/select")->assertOk();
        $this->postJson("/api/v1/estimations/{$est}/submit")->assertOk();
        $this->as($this->manager)->postJson("/api/v1/estimations/{$est}/approve")->assertOk();

        // ---- 3. Offer
        $this->as($this->charterer);
        $offer = $this->postJson('/api/v1/offers', ['enquiry_id' => $enq, 'vessel_id' => $vessel->id, 'estimation_scenario_id' => $sc])->assertCreated()->json('data');
        $rev = $offer['revisions'][0]['id'];
        $this->assertSame('per_day', $offer['revisions'][0]['rate_basis']);
        $this->assertEquals(12000, $offer['revisions'][0]['rate']);
        $this->assertEquals(6, $offer['revisions'][0]['period_days']);
        $this->putJson("/api/v1/offers/{$offer['id']}/revisions/{$rev}", ['valid_until' => '2026-09-30', 'terms' => 'Trip time charter. Charterer pays bunkers and port costs. Hire payable within 15 days of invoice.'])->assertOk();
        $this->postJson("/api/v1/offers/{$offer['id']}/revisions/{$rev}/send")->assertOk();
        $this->postJson("/api/v1/offers/{$offer['id']}/revisions/{$rev}/accept", ['note' => 'Accepted by email'])->assertOk();

        // ---- 4. Fixture + contract
        $fx = $this->postJson("/api/v1/offers/{$offer['id']}/revisions/{$rev}/convert-to-fixture")->assertCreated()->json('data');
        $this->postJson("/api/v1/fixtures/{$fx['id']}/submit")->assertOk();
        $this->as($this->manager)->postJson("/api/v1/fixtures/{$fx['id']}/approve")->assertOk();
        $this->as($this->charterer);
        $contract = $this->postJson("/api/v1/fixtures/{$fx['id']}/convert-to-contract")->assertCreated()->json('data');
        $this->assertSame('time_charter', $contract['contract_type']);
        $contract = $this->getJson("/api/v1/contracts/{$contract['id']}")->json('data');
        $this->assertCount(1, $contract['rates']);
        $this->assertSame(['hire_per_day', 'per_day'], [$contract['rates'][0]['rate_type'], $contract['rates'][0]['unit']]);
        $this->assertEquals(12000, $contract['rates'][0]['amount']);
        $this->putJson("/api/v1/contracts/{$contract['id']}", ['lock_version' => $contract['lock_version'], 'title' => 'Delta Commodities, MV Time Voyager trip time charter',
            'start_date' => '2026-10-01', 'end_date' => '2026-12-31', 'payment_terms_days' => 15, 'payment_terms_text' => 'Hire payable within 15 days of invoice'])->assertOk();
        $this->postJson("/api/v1/contracts/{$contract['id']}/submit")->assertOk();
        $this->as($this->commercial)->postJson("/api/v1/contracts/{$contract['id']}/approve")->assertOk();
        $this->as($this->charterer)->postJson("/api/v1/contracts/{$contract['id']}/activate")->assertOk();

        // ---- 5. Voyage (delivery 1 Oct 06:00 Dubai, redelivery 7 Oct 09:00 Dubai)
        $ops2 = $this->userWithRole(UserRole::Operations);
        $this->as($this->ops);
        $voyage = $this->postJson("/api/v1/fixtures/{$fx['id']}/convert-to-voyage")->assertCreated()->json('data');
        $v = $voyage['id'];
        $this->assertSame(['time_charter', $contract['id']], [$voyage['operation_type'], $voyage['contract_id']]);
        $move = fn (string $to, string $at) => $this->postJson("/api/v1/voyages/{$v}/transition", ['status' => $to, 'at' => $at])->assertOk();
        $move('nominated', '2026-09-30T10:00');
        $move('loading', '2026-10-01T06:00');

        $ja = $this->postJson("/api/v1/voyages/{$v}/port-calls", ['port_id' => $this->ports[0], 'purpose' => 'load', 'eta' => '2026-10-01T04:00',
            'ata' => '2026-10-01T05:00', 'atb' => '2026-10-01T06:00'])->assertCreated()->json('data');
        $rt = $this->postJson("/api/v1/voyages/{$v}/port-calls", ['port_id' => $this->ports[1], 'purpose' => 'discharge', 'eta' => '2026-10-04T07:00'])->assertCreated()->json('data');

        $this->putJson("/api/v1/voyages/{$v}/port-calls/{$ja['id']}", ['lock_version' => $ja['lock_version'], 'atd' => '2026-10-03T08:00'])->assertOk();
        $move('loaded', '2026-10-03T07:00');
        $move('sailing', '2026-10-03T08:00');
        $rt = $this->putJson("/api/v1/voyages/{$v}/port-calls/{$rt['id']}", ['lock_version' => $rt['lock_version'], 'ata' => '2026-10-04T07:00', 'atb' => '2026-10-04T08:00'])->assertOk()->json('data');
        $move('discharging', '2026-10-04T09:00');
        $this->putJson("/api/v1/voyages/{$v}/port-calls/{$rt['id']}", ['lock_version' => $rt['lock_version'], 'atd' => '2026-10-07T08:00'])->assertOk();

        // Off-hire: 12 hours of machinery breakdown. Agreeing it is required before the voyage can be finalized.
        $off = $this->postJson("/api/v1/voyages/{$v}/off-hire", ['from_at' => '2026-10-05T02:00', 'to_at' => '2026-10-05T14:00', 'reason_code' => 'breakdown',
            'description' => 'Main engine fault, 12 hours'])->assertCreated()->json('data');
        $this->assertSame(['draft', '12.0000'], [$off['status'], $off['hours']]);

        $this->postJson("/api/v1/voyages/{$v}/complete", ['at' => '2026-10-07T09:00'])->assertOk();
        // A draft off-hire event blocks finalization and cannot be waived.
        $this->postJson("/api/v1/voyages/{$v}/finalize")->assertStatus(409);
        $this->as($ops2)->postJson("/api/v1/voyages/{$v}/off-hire/{$off['id']}/agree", ['comment' => 'Agreed with charterer'])->assertOk();
        $this->as($this->ops);
        $cmp = $this->getJson("/api/v1/voyages/{$v}/comparison")->assertOk()->json('data');
        $metrics = collect($cmp['rows'])->keyBy('metric');
        $this->assertSame(['0.00', '0.50', '6.13'], [$metrics['off_hire_days']['values']['initial'], $metrics['off_hire_days']['values']['current'], $metrics['total_days']['values']['current']]);

        // ---- 6. Finance: hire for 6.125 days elapsed less 0.5 day off-hire = 5.625 days (the system does not deduct it for you)
        $finance = $this->userWithRole(UserRole::Finance);
        $this->as($finance);
        $hire = $this->postJson('/api/v1/voyage-revenues', ['voyage_id' => $v, 'contract_id' => $contract['id'], 'revenue_category_id' => RevenueCategory::query()->where('code', 'HIRE')->value('id'),
            'description' => 'Hire, 1 Oct 06:00 to 7 Oct 09:00 (6.125 days less 0.5 day off-hire)', 'quantity' => '5.625', 'rate' => '12000', 'currency' => 'USD',
            'commission_pct_total' => '3.75'])->assertCreated()->json('data');
        $this->assertSame(['67500.00', '2531.25'], [$hire['amount'], $hire['commission_amount']]);
        $this->postJson("/api/v1/voyage-revenues/{$hire['id']}/confirm")->assertOk();

        $opex = $this->postJson('/api/v1/voyage-expenses', ['voyage_id' => $v, 'contract_id' => $contract['id'], 'expense_category_id' => ExpenseCategory::query()->where('code', 'OPEX')->value('id'),
            'description' => 'Operational expense, crew and insurance (6.125 days x 3,000)', 'quantity' => '6.125', 'rate' => '3000', 'currency' => 'USD', 'incurred_at' => '2026-10-07'])
            ->assertCreated()->json('data');
        $this->assertSame('18375.00', $opex['amount']);
        $this->postJson("/api/v1/voyage-expenses/{$opex['id']}/confirm")->assertOk();
        $this->as($this->commercial)->postJson("/api/v1/voyage-expenses/{$opex['id']}/approve")->assertOk();
        $this->as($finance);

        $inv = $this->postJson('/api/v1/invoices', ['invoice_type' => 'hire', 'customer_company_id' => $delta->id, 'contract_id' => $contract['id'], 'voyage_id' => $v,
            'issue_date' => '2026-10-08', 'due_date' => '2026-10-23', 'currency' => 'USD', 'revenue_ids' => [$hire['id']]])->assertCreated()->json('data');
        $inv = $this->postJson("/api/v1/invoices/{$inv['id']}/issue")->assertOk()->json('data');
        $this->assertSame(['INV-2026-00001', '67500.00', 'issued'], [$inv['invoice_number'], $inv['total'], $inv['status']]);

        $pay = $this->postJson('/api/v1/payments', ['direction' => 'received', 'currency' => 'USD', 'company_id' => $delta->id, 'payment_date' => '2026-10-09', 'amount' => '67500',
            'method' => 'wire', 'bank_reference' => 'TT-DELTA-01'])->assertCreated()->json('data');
        $this->postJson("/api/v1/payments/{$pay['id']}/allocate", ['allocations' => [['invoice_id' => $inv['id'], 'amount' => '67500']]])->assertOk();
        $this->assertSame('paid', $this->getJson("/api/v1/invoices/{$inv['id']}")->json('data.status'));

        // ---- 7. Finalize + P&L
        $gates = $this->as($this->ops)->getJson("/api/v1/voyages/{$v}/finance-gates")->json('data');
        $this->assertSame(['ledger' => true, 'revenue' => true, 'port_da' => true, 'laytime' => true], array_map(fn ($g) => $g['passed'], $gates));
        $final = $this->postJson("/api/v1/voyages/{$v}/finalize")->assertOk()->json('data');
        $this->assertSame('finalized', $final['status']);

        $pnl = $this->as($finance)->getJson("/api/v1/voyages/{$v}/financials")->assertOk()->json('data');
        $rows = collect($pnl['rows'])->keyBy('metric');
        $this->assertSame(['72000.00', '67500.00', '-4500.00', '-6.25'], [$rows['gross_revenue']['estimate'], $rows['gross_revenue']['actual'], $rows['gross_revenue']['variance'], $rows['gross_revenue']['variance_pct']]);
        $this->assertSame(['2700.00', '2531.25', '-168.75', '-6.25'], [$rows['commission']['estimate'], $rows['commission']['actual'], $rows['commission']['variance'], $rows['commission']['variance_pct']]);
        $this->assertSame(['18000.00', '18375.00', '375.00', '2.08'], [$rows['total_costs']['estimate'], $rows['total_costs']['actual'], $rows['total_costs']['variance'], $rows['total_costs']['variance_pct']]);
        $this->assertSame(['51300.00', '46593.75', '-4706.25', '-9.17'], [$rows['net_profit']['estimate'], $rows['net_profit']['actual'], $rows['net_profit']['variance'], $rows['net_profit']['variance_pct']]);
        $this->assertSame(['49125.00', '69.03', '7600.94'], [$pnl['gross_profit'], $pnl['margin_pct'], $pnl['profit_per_day']]);
        $this->assertSame(['Hire' => '67500.00'], collect($pnl['revenue_by_category'])->pluck('amount', 'category')->all());
        $this->assertSame(['Operational expense' => '18375.00'], collect($pnl['expense_by_category'])->pluck('amount', 'category')->all());
        $this->assertSame([], $this->getJson('/api/v1/receivables/aging')->json('data.customers'));
    }
}
