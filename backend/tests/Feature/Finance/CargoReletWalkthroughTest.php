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
 * Replays docs/cargo-relet-dataflow.md (we hold a head charter on someone else's ship and sub-let it to a cargo owner at a
 * freight rate) end to end through the API with the exact dummy data from that document and pins every headline figure.
 * If a calculation or workflow rule changes, this test and the document change together.
 */
class CargoReletWalkthroughTest extends OperationsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-10 12:00:00');
    }

    public function test_cargo_relet_from_enquiry_to_final_profit(): void
    {
        $hfo = FuelType::query()->where('code', 'HFO')->value('id');
        $zenith = Company::query()->create(['code' => 'ZTL', 'legal_name' => 'Zenith Trading LLC', 'normalized_name' => 'zenith trading']);
        $zenith->roles()->create(['role' => 'charterer']);
        $owner = Company::query()->create(['code' => 'BHS', 'legal_name' => 'Blue Horizon Shipping Ltd', 'normalized_name' => 'blue horizon shipping']);
        $owner->roles()->create(['role' => 'owner']);
        $oceanic = Company::query()->create(['code' => 'OFT', 'legal_name' => 'Oceanic Fuels Trading LLC', 'normalized_name' => 'oceanic fuels trading']);
        $oceanic->roles()->create(['role' => 'supplier']);

        // The head-chartered ship is an ordinary vessel record owned by the third party.
        // 13.75 kn x 24 h = 330 NM per day, so the 330 NM sea leg takes exactly 1 day.
        $vessel = Vessel::query()->create(['code' => 'RLT01', 'name' => 'MV Chartered Star', 'vessel_type_id' => VesselType::query()->value('id'),
            'owner_company_id' => $owner->id, 'service_speed_kn' => '13.75']);
        $profile = $vessel->consumptionProfiles()->create(['name' => 'Design', 'effective_from' => '2026-01-01', 'is_default' => true]);
        $profile->rates()->create(['mode' => 'sea_laden', 'speed_kn' => '13.75', 'fuel_type_id' => $hfo, 'consumption_mt_per_day' => '20']);
        $profile->rates()->create(['mode' => 'port_working', 'speed_kn' => '0', 'fuel_type_id' => $hfo, 'consumption_mt_per_day' => '5']);

        // ---- 1. Enquiry (cargo relet: 3,000 MT at 62 per MT)
        $this->as($this->charterer);
        $enq = $this->postJson('/api/v1/enquiries', [
            'business_type' => 'cargo_relet', 'source' => 'direct', 'currency' => 'USD', 'charterer_company_id' => $zenith->id, 'cargo_description' => 'General cargo',
            'quantity' => '3000', 'quantity_unit' => 'mt', 'laycan_from' => '2026-10-01', 'laycan_to' => '2026-10-05', 'rate_idea' => '62', 'rate_basis' => 'per_mt',
            'commission_terms' => '2.5% address + 1.25% brokerage',
            'ports' => [['port_id' => $this->ports[0], 'purpose' => 'load'], ['port_id' => $this->ports[1], 'purpose' => 'discharge']],
        ])->assertCreated()->json('data.id');

        // ---- 2. Estimation
        $res = $this->postJson('/api/v1/estimations', ['enquiry_id' => $enq, 'vessel_id' => $vessel->id])->assertCreated();
        $est = $res->json('data.id');
        $this->assertSame('cargo_relet', $res->json('data.estimation_type'));
        $sc = $res->json('data.scenarios.0.id');
        $s = $this->getJson("/api/v1/estimations/{$est}/scenarios/{$sc}")->json('data');
        $in = $s['inputs'];

        // What the system pre-fills for a cargo relet: freight per MT (qty and rate from the enquiry) and an empty head-charter cost.
        $this->assertSame(['FREIGHT', 'per_mt', 'owner'], [$in['revenue_items'][0]['category_code'], $in['revenue_items'][0]['basis'], $in['fuel_prices'][0]['account']]);
        $this->assertEquals([3000, 62], [$in['revenue_items'][0]['quantity'], $in['revenue_items'][0]['rate']]);
        $this->assertCount(1, $in['cost_items']);
        $this->assertSame(['TONNAGE', 'tonnage', 'per_day', null], [$in['cost_items'][0]['category_code'], $in['cost_items'][0]['group'], $in['cost_items'][0]['basis'], $in['cost_items'][0]['rate']]);
        $this->assertSame('laden', $in['legs'][0]['condition']);

        $in['fuel_prices'][0]['price_per_mt'] = '600';
        $in['calls'][0]['working_days'] = '2';
        $in['calls'][0]['port_cost']['amount'] = '4500';
        $in['calls'][0]['agency_cost']['amount'] = '600';
        $in['calls'][1]['working_days'] = '3';
        $in['calls'][1]['port_cost']['amount'] = '3500';
        $in['calls'][1]['agency_cost']['amount'] = '600';
        $in['revenue_items'][0]['address_pct'] = '2.5';
        $in['revenue_items'][0]['brokerage_pct'] = '1.25';
        $in['cost_items'][0]['rate'] = '11000'; // head-charter hire we pay the ship owner, per day over the elapsed days
        $saved = $this->putJson("/api/v1/estimations/{$est}/scenarios/{$sc}", ['lock_version' => $s['lock_version'], 'inputs' => $in])->assertOk()->json('data');
        $this->assertSame('calculated', $saved['calc_status']);
        $r = $saved['result'];
        $this->assertSame(['330.00', '1.000000', '5.000000', '6.000000'], [$r['sea_distance_nm'], $r['sea_days'], $r['port_days'], $r['total_days']]);
        $this->assertSame(['45.000', '27000.00', '8000.00', '1200.00', '36200.00'], [$r['fuel_total_mt'], $r['fuel_cost'], $r['port_costs'], $r['agency_costs'], $r['voyage_costs']]);
        $this->assertSame(['66000.00', '102200.00'], [$r['tonnage_cost'], $r['total_costs']]);
        $this->assertSame(['186000.00', '6975.00', '179025.00', '76825.00', '23804.17', '35.3939'], [$r['gross_revenue'], $r['total_commission'], $r['net_revenue'], $r['profit'], $r['tce_per_day'], $r['breakeven_rate']]);
        $this->assertSame(['41.3038', '12804.17'], [$r['profit_margin_pct'], $r['profit_per_day']]);
        $this->assertNotNull(collect($r['trace'])->firstWhere('ref', 'R4'));
        $this->postJson("/api/v1/estimations/{$est}/scenarios/{$sc}/select")->assertOk();
        $this->postJson("/api/v1/estimations/{$est}/submit")->assertOk();
        $this->as($this->manager)->postJson("/api/v1/estimations/{$est}/approve")->assertOk();

        // ---- 3. Offer
        $this->as($this->charterer);
        $offer = $this->postJson('/api/v1/offers', ['enquiry_id' => $enq, 'vessel_id' => $vessel->id, 'estimation_scenario_id' => $sc])->assertCreated()->json('data');
        $rev = $offer['revisions'][0]['id'];
        $this->assertSame('per_mt', $offer['revisions'][0]['rate_basis']);
        $this->assertEquals([62, 3000], [$offer['revisions'][0]['rate'], $offer['revisions'][0]['quantity']]);
        $this->putJson("/api/v1/offers/{$offer['id']}/revisions/{$rev}", ['valid_until' => '2026-09-30', 'terms' => 'Subject to charter party. Freight payable within 30 days of discharge.'])->assertOk();
        $this->postJson("/api/v1/offers/{$offer['id']}/revisions/{$rev}/send")->assertOk();
        $this->postJson("/api/v1/offers/{$offer['id']}/revisions/{$rev}/accept", ['note' => 'Accepted by email'])->assertOk();

        // ---- 4. Fixture + contract (a relet is formalised as a voyage charter contract, assumption A-CT-1)
        $fx = $this->postJson("/api/v1/offers/{$offer['id']}/revisions/{$rev}/convert-to-fixture")->assertCreated()->json('data');
        $this->assertSame('cargo_relet', $fx['business_type']);
        $this->postJson("/api/v1/fixtures/{$fx['id']}/submit")->assertOk();
        $this->as($this->manager)->postJson("/api/v1/fixtures/{$fx['id']}/approve")->assertOk();
        $this->as($this->charterer);
        $contract = $this->postJson("/api/v1/fixtures/{$fx['id']}/convert-to-contract")->assertCreated()->json('data');
        $this->assertSame('voyage_charter', $contract['contract_type']);
        $contract = $this->getJson("/api/v1/contracts/{$contract['id']}")->json('data');
        $this->assertSame(['freight_per_mt', 'per_mt'], [$contract['rates'][0]['rate_type'], $contract['rates'][0]['unit']]);
        $this->putJson("/api/v1/contracts/{$contract['id']}", ['lock_version' => $contract['lock_version'], 'title' => 'Zenith Trading, MV Chartered Star relet', 'start_date' => '2026-10-01',
            'end_date' => '2026-12-31', 'payment_terms_days' => 30, 'payment_terms_text' => 'Freight payable within 30 days of discharge'])->assertOk();
        $this->postJson("/api/v1/contracts/{$contract['id']}/submit")->assertOk();
        $this->as($this->commercial)->postJson("/api/v1/contracts/{$contract['id']}/approve")->assertOk();
        $this->as($this->charterer)->postJson("/api/v1/contracts/{$contract['id']}/activate")->assertOk();

        // ---- 5. Voyage (commenced 1 Oct 06:00 Dubai, completed 7 Oct 09:00 Dubai = 6.125 days)
        $ops2 = $this->userWithRole(UserRole::Operations);
        $this->as($this->ops);
        $voyage = $this->postJson("/api/v1/fixtures/{$fx['id']}/convert-to-voyage")->assertCreated()->json('data');
        $v = $voyage['id'];
        $this->assertSame(['voyage', $contract['id']], [$voyage['operation_type'], $voyage['contract_id']]);
        $move = fn (string $to, string $at) => $this->postJson("/api/v1/voyages/{$v}/transition", ['status' => $to, 'at' => $at])->assertOk();
        $move('nominated', '2026-09-30T10:00');
        $move('loading', '2026-10-01T06:00');

        $ja = $this->postJson("/api/v1/voyages/{$v}/port-calls", ['port_id' => $this->ports[0], 'purpose' => 'load', 'eta' => '2026-10-01T04:00',
            'ata' => '2026-10-01T05:00', 'atb' => '2026-10-01T06:00'])->assertCreated()->json('data');
        $rt = $this->postJson("/api/v1/voyages/{$v}/port-calls", ['port_id' => $this->ports[1], 'purpose' => 'discharge', 'eta' => '2026-10-04T07:00'])->assertCreated()->json('data');

        // We buy the bunkers (owner account in a relet): 46 MT against 45 MT planned.
        $stem = $this->postJson('/api/v1/bunker-stems', ['vessel_id' => $vessel->id, 'voyage_id' => $v, 'port_call_id' => $ja['id'], 'fuel_type_id' => $hfo,
            'supplier_company_id' => $oceanic->id, 'ordered_on' => '2026-09-30', 'ordered_mt' => '46', 'price_per_mt' => '600', 'currency' => 'USD'])->assertCreated()->json('data');
        $stem = $this->postJson("/api/v1/bunker-stems/{$stem['id']}/deliver", ['lock_version' => $stem['lock_version'], 'delivered_at' => '2026-10-02T10:00', 'delivered_mt' => '46', 'bdn_number' => 'BDN-0201'])
            ->assertOk()->json('data');
        $this->assertSame('27600.00', $stem['total_amount']);

        $ja = $this->putJson("/api/v1/voyages/{$v}/port-calls/{$ja['id']}", ['lock_version' => $ja['lock_version'], 'atd' => '2026-10-03T08:00'])->assertOk()->json('data');
        $move('loaded', '2026-10-03T07:00');
        $move('sailing', '2026-10-03T08:00');
        $rt = $this->putJson("/api/v1/voyages/{$v}/port-calls/{$rt['id']}", ['lock_version' => $rt['lock_version'], 'ata' => '2026-10-04T07:00', 'atb' => '2026-10-04T08:00'])->assertOk()->json('data');
        $move('discharging', '2026-10-04T09:00');
        $rt = $this->putJson("/api/v1/voyages/{$v}/port-calls/{$rt['id']}", ['lock_version' => $rt['lock_version'], 'atd' => '2026-10-07T08:00'])->assertOk()->json('data');

        $cat = fn (string $code) => DaCostCategory::query()->where('code', $code)->value('id');
        $mkDa = function (array $call, int $portId, array $items) use ($v, $ops2) {
            $da = $this->postJson('/api/v1/port-das', ['voyage_id' => $v, 'port_call_id' => $call['id'], 'port_id' => $portId, 'da_type' => 'final', 'currency' => 'USD'])->assertCreated()->json('data');
            $this->postJson("/api/v1/port-das/{$da['id']}/items", ['items' => $items])->assertOk();
            $this->postJson("/api/v1/port-das/{$da['id']}/submit")->assertOk();
            $this->as($ops2)->postJson("/api/v1/port-das/{$da['id']}/approve")->assertOk();
            $this->as($this->ops);
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

        $this->postJson("/api/v1/voyages/{$v}/complete", ['at' => '2026-10-07T09:00'])->assertOk();

        // ---- 6. Finance
        $finance = $this->userWithRole(UserRole::Finance);
        $this->as($finance);
        $freight = $this->postJson('/api/v1/voyage-revenues', ['voyage_id' => $v, 'contract_id' => $contract['id'], 'revenue_category_id' => RevenueCategory::query()->where('code', 'FREIGHT')->value('id'),
            'description' => 'Freight, Jebel Ali to Ras Tanura', 'quantity' => '3000', 'rate' => '62', 'currency' => 'USD', 'commission_pct_total' => '3.75'])->assertCreated()->json('data');
        $this->assertSame(['186000.00', '6975.00'], [$freight['amount'], $freight['commission_amount']]);
        $this->postJson("/api/v1/voyage-revenues/{$freight['id']}/confirm")->assertOk();

        $mkExp = fn (string $code, string $desc, array $money) => $this->postJson('/api/v1/voyage-expenses', ['voyage_id' => $v, 'contract_id' => $contract['id'],
            'expense_category_id' => ExpenseCategory::query()->where('code', $code)->value('id'), 'description' => $desc, 'currency' => 'USD', ...$money])->assertCreated()->json('data');
        $bunker = $mkExp('BUNKER', 'Bunkers, 46 MT HFO @ 600 (BDN-0201)', ['quantity' => '46', 'rate' => '600', 'supplier_company_id' => $oceanic->id, 'incurred_at' => '2026-10-02']);
        // The head-charter hire we pay the ship owner: 6.125 days x 11,000. The estimate carried this as the "tonnage" cost.
        $tonnage = $mkExp('TONNAGE', 'Head charter hire, MV Chartered Star (6.125 days x 11,000)', ['quantity' => '6.125', 'rate' => '11000', 'supplier_company_id' => $owner->id, 'incurred_at' => '2026-10-07']);
        $this->assertSame(['27600.00', '67375.00'], [$bunker['amount'], $tonnage['amount']]);
        foreach ([$bunker, $tonnage] as $e) {
            $this->postJson("/api/v1/voyage-expenses/{$e['id']}/confirm")->assertOk();
            $this->as($this->commercial)->postJson("/api/v1/voyage-expenses/{$e['id']}/approve")->assertOk();
            $this->as($finance);
        }
        foreach ($this->getJson("/api/v1/voyage-expenses?voyage_id={$v}&per_page=50")->json('data') as $e) {
            if ($e['status'] === 'confirmed') {
                $this->as($this->commercial)->postJson("/api/v1/voyage-expenses/{$e['id']}/approve")->assertOk();
                $this->as($finance);
            }
        }

        $inv = $this->postJson('/api/v1/invoices', ['invoice_type' => 'freight', 'customer_company_id' => $zenith->id, 'contract_id' => $contract['id'], 'voyage_id' => $v,
            'issue_date' => '2026-10-08', 'due_date' => '2026-11-07', 'currency' => 'USD', 'revenue_ids' => [$freight['id']]])->assertCreated()->json('data');
        $inv = $this->postJson("/api/v1/invoices/{$inv['id']}/issue")->assertOk()->json('data');
        $this->assertSame(['INV-2026-00001', '186000.00', 'issued'], [$inv['invoice_number'], $inv['total'], $inv['status']]);
        $pay = $this->postJson('/api/v1/payments', ['direction' => 'received', 'currency' => 'USD', 'company_id' => $zenith->id, 'payment_date' => '2026-10-09', 'amount' => '186000',
            'method' => 'wire', 'bank_reference' => 'TT-ZENITH-01'])->assertCreated()->json('data');
        $this->postJson("/api/v1/payments/{$pay['id']}/allocate", ['allocations' => [['invoice_id' => $inv['id'], 'amount' => '186000']]])->assertOk();
        $this->assertSame('paid', $this->getJson("/api/v1/invoices/{$inv['id']}")->json('data.status'));

        // Two supplier invoices: bunkers and the head-charter hire.
        foreach ([[$oceanic, 'OF-INV-8801', '27600', 'TT-OUT-8801'], [$owner, 'BH-HIRE-1001', '67375', 'TT-OUT-1001']] as [$company, $ref, $amount, $bank]) {
            $payable = $this->postJson('/api/v1/payables', ['supplier_company_id' => $company->id, 'supplier_invoice_ref' => $ref, 'currency' => 'USD',
                'issue_date' => '2026-10-07', 'due_date' => '2026-11-06', 'subtotal' => $amount, 'tax' => '0'])->assertCreated()->json('data');
            $this->as($this->commercial)->postJson("/api/v1/payables/{$payable['id']}/approve")->assertOk();
            $this->as($finance);
            $out = $this->postJson('/api/v1/payments', ['direction' => 'paid', 'currency' => 'USD', 'company_id' => $company->id, 'payment_date' => '2026-10-09', 'amount' => $amount,
                'method' => 'wire', 'bank_reference' => $bank])->assertCreated()->json('data');
            $this->postJson("/api/v1/payments/{$out['id']}/allocate", ['allocations' => [['payable_id' => $payable['id'], 'amount' => $amount]]])->assertOk();
            $this->assertSame('paid', $this->getJson("/api/v1/payables/{$payable['id']}")->json('data.status'));
        }

        // ---- 7. Finalize + P&L
        $gates = $this->as($this->ops)->getJson("/api/v1/voyages/{$v}/finance-gates")->json('data');
        $this->assertSame(['ledger' => true, 'revenue' => true, 'port_da' => true, 'laytime' => true], array_map(fn ($g) => $g['passed'], $gates));
        $this->assertSame('finalized', $this->postJson("/api/v1/voyages/{$v}/finalize")->assertOk()->json('data.status'));

        $pnl = $this->as($finance)->getJson("/api/v1/voyages/{$v}/financials")->assertOk()->json('data');
        $rows = collect($pnl['rows'])->keyBy('metric');
        $this->assertSame(['186000.00', '186000.00', '0.00'], [$rows['gross_revenue']['estimate'], $rows['gross_revenue']['actual'], $rows['gross_revenue']['variance']]);
        $this->assertSame(['6975.00', '6975.00', '0.00'], [$rows['commission']['estimate'], $rows['commission']['actual'], $rows['commission']['variance']]);
        $this->assertSame(['102200.00', '105275.00', '3075.00', '3.01'], [$rows['total_costs']['estimate'], $rows['total_costs']['actual'], $rows['total_costs']['variance'], $rows['total_costs']['variance_pct']]);
        $this->assertSame(['76825.00', '73750.00', '-3075.00', '-4.00'], [$rows['net_profit']['estimate'], $rows['net_profit']['actual'], $rows['net_profit']['variance'], $rows['net_profit']['variance_pct']]);
        $this->assertSame(['80725.00', '39.65', '12031.00'], [$pnl['gross_profit'], $pnl['margin_pct'], $pnl['profit_per_day']]);
        $this->assertSame(['Head charter / tonnage cost' => '67375.00', 'Bunkers' => '27600.00', 'Port charges' => '9100.00', 'Agency fees' => '1200.00'],
            collect($pnl['expense_by_category'])->pluck('amount', 'category')->all());
        $this->assertSame([], $this->getJson('/api/v1/receivables/aging')->json('data.customers'));
    }
}
