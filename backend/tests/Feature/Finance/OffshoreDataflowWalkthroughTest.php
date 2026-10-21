<?php

namespace Tests\Feature\Finance;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\DaCostCategory;
use App\Models\ExpenseCategory;
use App\Models\FuelType;
use App\Models\OffshoreActivityType;
use App\Models\OffshoreLocation;
use App\Models\Port;
use App\Models\PortDistance;
use App\Models\RevenueCategory;
use App\Models\Vessel;
use App\Models\VesselType;
use Illuminate\Support\Carbon;
use Tests\Feature\Operations\OperationsTestCase;

/**
 * Replays docs/offshore-dataflow.md (offshore day-rate charter) end to end through the API with the exact dummy data from
 * that document and pins every headline figure. If a calculation or workflow rule changes, this test and the document change together.
 */
class OffshoreDataflowWalkthroughTest extends OperationsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-10 12:00:00');
    }

    public function test_offshore_day_rate_charter_from_enquiry_to_final_profit(): void
    {
        $mgo = FuelType::query()->where('code', 'MGO')->value('id');
        $client = Company::query()->create(['code' => 'ARE', 'legal_name' => 'Alpha Resources Energy LLC', 'normalized_name' => 'alpha resources energy']);
        $client->roles()->create(['role' => 'charterer']);
        $supplier = Company::query()->create(['code' => 'OFT', 'legal_name' => 'Oceanic Fuels Trading LLC', 'normalized_name' => 'oceanic fuels trading']);
        $supplier->roles()->create(['role' => 'supplier']);

        $base = Port::query()->find($this->ports[0]); // Jebel Ali, Asia/Dubai
        $field = OffshoreLocation::query()->create(['code' => 'ALPHA-1', 'name' => 'Alpha Field', 'field_name' => 'Alpha', 'latitude' => '25.5', 'longitude' => '55.2',
            'timezone' => 'Asia/Dubai', 'nearest_port_id' => $base->id]);
        PortDistance::query()->create(['from_type' => 'port', 'from_id' => $base->id, 'to_type' => 'location', 'to_id' => $field->id, 'distance_nm' => '120', 'provider' => 'manual', 'calculated_at' => now()]);

        $vessel = Vessel::query()->create(['code' => 'PSV01', 'name' => 'MV Gulf Supporter', 'vessel_type_id' => VesselType::query()->where('code', 'PSV')->value('id'), 'service_speed_kn' => '12']);
        $profile = $vessel->consumptionProfiles()->create(['name' => 'Design', 'effective_from' => '2026-01-01', 'is_default' => true]);
        foreach ([['sea_laden', '12', '15'], ['sea_ballast', '12', '14'], ['port_working', '0', '3'], ['dp_operation', '0', '7.5'], ['standby', '0', '2.5']] as [$mode, $speed, $mt]) {
            $profile->rates()->create(['mode' => $mode, 'speed_kn' => $speed, 'fuel_type_id' => $mgo, 'consumption_mt_per_day' => $mt]);
        }

        // ---- 1. Enquiry (offshore charter, rate per day)
        $this->as($this->charterer);
        $enq = $this->postJson('/api/v1/enquiries', [
            'business_type' => 'offshore_charter', 'charterer_company_id' => $client->id, 'cargo_description' => 'Platform supply support, Alpha Field',
            'period_days' => '10', 'rate_idea' => '14500', 'rate_basis' => 'per_day', 'currency' => 'USD', 'laycan_from' => '2026-09-22', 'laycan_to' => '2026-09-23',
            'ports' => [['port_id' => $base->id, 'purpose' => 'load'], ['offshore_location_id' => $field->id, 'purpose' => 'offshore_ops'], ['port_id' => $base->id, 'purpose' => 'redelivery']],
        ])->assertCreated()->json('data.id');

        // ---- 2. Estimation
        $res = $this->postJson('/api/v1/estimations', ['enquiry_id' => $enq, 'vessel_id' => $vessel->id])->assertCreated();
        $est = $res->json('data.id');
        $this->assertSame('offshore_day_rate', $res->json('data.estimation_type'));
        $sc = $res->json('data.scenarios.0.id');
        $s = $this->getJson("/api/v1/estimations/{$est}/scenarios/{$sc}")->json('data');
        $in = $s['inputs'];
        $in['legs'][1]['condition'] = 'ballast';
        $in['fuel_prices'][0]['price_per_mt'] = '800';
        $in['calls'][0]['working_days'] = '1';
        $in['calls'][0]['port_cost']['amount'] = '5000';
        $in['calls'][0]['agency_cost']['amount'] = '800';
        $in['calls'][1]['dp_days'] = '8';
        $in['calls'][1]['standby_days'] = '1';
        $in['calls'][2]['working_days'] = '1';
        $in['calls'][2]['port_cost']['amount'] = '3000';
        $in['calls'][2]['agency_cost']['amount'] = '600';
        $in['revenue_items'][0]['quantity'] = '10';
        $in['revenue_items'][0]['address_pct'] = '2.5';
        $in['revenue_items'][0]['brokerage_pct'] = '1.25';
        $cat = fn (string $cls, string $code) => $cls::query()->where('code', $code)->first();
        foreach ([['STANDBY', 'Standby day rate', 'per_day', '1', '9000'], ['MOBFEE', 'Mobilization fee', 'lump_sum', null, '25000'], ['DEMOBFEE', 'Demobilization fee', 'lump_sum', null, '20000']] as [$code, $d, $basis, $qty, $rate]) {
            $c = $cat(RevenueCategory::class, $code);
            $in['revenue_items'][] = ['revenue_category_id' => $c->id, 'description' => $d, 'basis' => $basis, 'quantity' => $qty, 'rate' => $rate, 'currency' => 'USD', 'fx_rate' => '1',
                'commissionable' => false, 'address_pct' => '0', 'brokerage_pct' => '0', 'other_pct' => '0', 'primary' => false];
        }
        $opex = $cat(ExpenseCategory::class, 'OPEX');
        $in['cost_items'][] = ['expense_category_id' => $opex->id, 'description' => 'Operational expense', 'basis' => 'per_day', 'quantity' => '10', 'rate' => '2500', 'currency' => 'USD', 'fx_rate' => '1', 'account' => 'owner'];
        $saved = $this->putJson("/api/v1/estimations/{$est}/scenarios/{$sc}", ['lock_version' => $s['lock_version'], 'inputs' => $in])->assertOk()->json('data');
        $this->assertSame('calculated', $saved['calc_status']);
        $r = $saved['result'];
        $this->assertSame(['240.00', '0.833333', '11.000000', '11.833333'], [$r['sea_distance_nm'], $r['sea_days'], $r['port_days'], $r['total_days']]);
        $this->assertSame(['80.583', '64466.67', '8000.00', '1400.00', '25000.00', '73866.67', '98866.67'], [$r['fuel_total_mt'], $r['fuel_cost'], $r['port_costs'], $r['agency_costs'], $r['operational_costs'], $r['voyage_costs'], $r['total_costs']]);
        $this->assertSame(['199000.00', '5437.50', '193562.50', '94695.83', '10115.14'], [$r['gross_revenue'], $r['total_commission'], $r['net_revenue'], $r['profit'], $r['tce_per_day']]);
        $this->postJson("/api/v1/estimations/{$est}/scenarios/{$sc}/select")->assertOk();
        $this->postJson("/api/v1/estimations/{$est}/submit")->assertOk();
        $this->as($this->manager)->postJson("/api/v1/estimations/{$est}/approve")->assertOk();

        // ---- 3-5. Offer, fixture, contract
        $this->as($this->charterer);
        $offer = $this->postJson('/api/v1/offers', ['enquiry_id' => $enq, 'vessel_id' => $vessel->id, 'estimation_scenario_id' => $sc])->assertCreated()->json('data');
        $rev = $offer['revisions'][0]['id'];
        $this->putJson("/api/v1/offers/{$offer['id']}/revisions/{$rev}", ['valid_until' => '2026-09-21', 'terms' => 'Day rate charter. Fuel for owner account. Mob/demob lump sums apply.'])->assertOk();
        $this->postJson("/api/v1/offers/{$offer['id']}/revisions/{$rev}/send")->assertOk();
        $this->postJson("/api/v1/offers/{$offer['id']}/revisions/{$rev}/accept", ['note' => 'Accepted by email'])->assertOk();
        $fx = $this->postJson("/api/v1/offers/{$offer['id']}/revisions/{$rev}/convert-to-fixture")->assertCreated()->json('data');
        $this->postJson("/api/v1/fixtures/{$fx['id']}/submit")->assertOk();
        $this->as($this->manager)->postJson("/api/v1/fixtures/{$fx['id']}/approve")->assertOk();
        $this->as($this->charterer);
        $contract = $this->postJson("/api/v1/fixtures/{$fx['id']}/convert-to-contract")->assertCreated()->json('data');
        $cid = $contract['id'];
        $t = fn (string $type, string $amount, string $unit, ?string $activity = null, string $desc = '') => ['rate_type' => $type, 'amount' => $amount, 'currency' => 'USD', 'unit' => $unit,
            'offshore_activity_type_id' => $activity ? OffshoreActivityType::query()->where('code', $activity)->value('id') : null, 'description' => $desc];
        $contract = $this->putJson("/api/v1/contracts/{$cid}", ['lock_version' => $contract['lock_version'], 'title' => 'Alpha Field supply support', 'end_date' => '2026-12-31',
            'payment_terms_days' => 30])->assertOk()->json('data');
        $current = collect($contract['rates'])->map(fn ($r) => array_intersect_key($r, array_flip(['rate_type', 'amount', 'currency', 'unit', 'offshore_activity_type_id', 'description', 'effective_from', 'effective_to'])))->all();
        $this->putJson("/api/v1/contracts/{$cid}/rates", ['lock_version' => $contract['lock_version'], 'rates' => [
            ...$current,
            $t('standby_day_rate', '9000', 'per_day', null, 'Standby (weather / waiting)'),
            $t('mobilization_fee', '25000', 'lump_sum', null, 'Mobilization'),
            $t('demobilization_fee', '20000', 'lump_sum', null, 'Demobilization'),
            $t('hourly', '650', 'per_hour', 'ROV', 'ROV support hourly'),
        ]])->assertOk();
        $this->postJson("/api/v1/contracts/{$cid}/submit")->assertOk();
        $this->as($this->commercial)->postJson("/api/v1/contracts/{$cid}/approve")->assertOk();
        $this->as($this->charterer)->postJson("/api/v1/contracts/{$cid}/activate")->assertOk();

        // ---- 6. Voyage + project
        $ops2 = $this->userWithRole(UserRole::Operations);
        $this->as($this->ops);
        $voyage = $this->postJson("/api/v1/fixtures/{$fx['id']}/convert-to-voyage")->assertCreated()->json('data');
        $v = $voyage['id'];
        $this->assertSame('offshore', $voyage['operation_type']);
        $this->assertSame($cid, $voyage['contract_id']);
        $project = $this->postJson('/api/v1/offshore-projects', ['code' => 'ALPHA-2026', 'name' => 'Alpha Field supply support 2026', 'contract_id' => $cid,
            'offshore_location_id' => $field->id, 'field_name' => 'Alpha', 'start_date' => '2026-09-22', 'end_date' => '2026-12-31'])->assertCreated()->json('data');
        $move = fn (string $to, string $at) => $this->postJson("/api/v1/voyages/{$v}/transition", ['status' => $to, 'at' => $at])->assertOk();
        $move('nominated', '2026-09-22T16:00');

        $c1 = $this->postJson("/api/v1/voyages/{$v}/port-calls", ['port_id' => $base->id, 'purpose' => 'supply', 'eta' => '2026-09-23T06:00', 'ata' => '2026-09-23T06:00', 'atb' => '2026-09-23T07:00'])
            ->assertCreated()->json('data');
        $c2 = $this->postJson("/api/v1/voyages/{$v}/port-calls", ['offshore_location_id' => $field->id, 'purpose' => 'offshore_ops', 'eta' => '2026-09-24T08:00'])->assertCreated()->json('data');
        $c3 = $this->postJson("/api/v1/voyages/{$v}/port-calls", ['port_id' => $base->id, 'purpose' => 'other', 'eta' => '2026-10-06T13:30'])->assertCreated()->json('data');
        $move('mobilizing', '2026-09-23T10:00');

        // Bunkers at Jebel Ali
        $stem = $this->postJson('/api/v1/bunker-stems', ['vessel_id' => $vessel->id, 'voyage_id' => $v, 'port_call_id' => $c1['id'], 'fuel_type_id' => $mgo,
            'supplier_company_id' => $supplier->id, 'ordered_on' => '2026-09-22', 'ordered_mt' => '95', 'price_per_mt' => '800', 'currency' => 'USD'])->assertCreated()->json('data');
        $this->postJson("/api/v1/bunker-stems/{$stem['id']}/deliver", ['lock_version' => $stem['lock_version'], 'delivered_at' => '2026-09-23T14:00', 'delivered_mt' => '95', 'bdn_number' => 'BDN-0101'])
            ->assertOk()->assertJsonPath('data.total_amount', '76000.00');

        // Activities
        $types = fn (string $code) => OffshoreActivityType::query()->where('code', $code)->value('id');
        $mk = function (string $type, string $from, string $to, string $desc, array $hours) use ($vessel, $v, $project, $field, $types, $ops2) {
            $a = $this->postJson('/api/v1/offshore-activities', ['vessel_id' => $vessel->id, 'voyage_id' => $v, 'offshore_project_id' => $project['id'], 'offshore_location_id' => $field->id,
                'offshore_activity_type_id' => $types($type), 'description' => $desc, 'start_at' => $from, 'end_at' => $to, ...$hours])->assertCreated()->json('data');
            $this->postJson("/api/v1/offshore-activities/{$a['id']}/submit")->assertOk();
            $verified = $this->as($ops2)->postJson("/api/v1/offshore-activities/{$a['id']}/verify", ['comment' => 'OK'])->assertOk()->json('data');
            $this->as($this->ops);

            return $verified;
        };
        $acts = [];
        $acts['MOB'] = $mk('MOB', '2026-09-23T10:00', '2026-09-23T22:00', 'Mobilization at Jebel Ali', ['billable_hours' => '0', 'non_billable_hours' => '12', 'standby_hours' => '0']);
        $c1 = $this->putJson("/api/v1/voyages/{$v}/port-calls/{$c1['id']}", ['lock_version' => $c1['lock_version'], 'atd' => '2026-09-23T22:00'])->assertOk()->json('data');
        $move('offshore_operation', '2026-09-24T08:00');
        $c2 = $this->putJson("/api/v1/voyages/{$v}/port-calls/{$c2['id']}", ['lock_version' => $c2['lock_version'], 'ata' => '2026-09-24T08:00'])->assertOk()->json('data');
        $acts['S1'] = $mk('SUPPLY', '2026-09-24T08:00', '2026-09-26T08:00', 'Supply run 1', ['billable_hours' => '48']);
        $acts['ROV'] = $mk('ROV', '2026-09-26T08:00', '2026-09-26T15:30', 'ROV inspection', ['billable_hours' => '7.5']);
        $acts['S2'] = $mk('SUPPLY', '2026-09-26T15:30', '2026-09-29T15:30', 'Supply run 2', ['billable_hours' => '72']);
        $move('standby', '2026-09-29T15:30');
        $acts['SB'] = $mk('STANDBY', '2026-09-29T15:30', '2026-09-30T15:30', 'Waiting on weather', ['billable_hours' => '0', 'standby_hours' => '24']);
        $move('offshore_operation', '2026-09-30T15:30');
        $acts['S3'] = $mk('SUPPLY', '2026-09-30T15:30', '2026-10-05T15:30', 'Supply run 3', ['billable_hours' => '120']);
        $move('demobilizing', '2026-10-05T15:30');
        $acts['DEMOB'] = $mk('DEMOB', '2026-10-05T15:30', '2026-10-06T03:30', 'Demobilization', ['billable_hours' => '0', 'non_billable_hours' => '12', 'standby_hours' => '0']);

        $this->assertSame(['MOB' => '25000.00', 'S1' => '29000.00', 'ROV' => '4875.00', 'S2' => '43500.00', 'SB' => '9000.00', 'S3' => '72500.00', 'DEMOB' => '20000.00'],
            array_map(fn ($a) => $a['revenue_amount'], $acts));
        $c2 = $this->putJson("/api/v1/voyages/{$v}/port-calls/{$c2['id']}", ['lock_version' => $c2['lock_version'], 'atd' => '2026-10-06T03:30'])->assertOk()->json('data');
        $c3 = $this->putJson("/api/v1/voyages/{$v}/port-calls/{$c3['id']}", ['lock_version' => $c3['lock_version'], 'ata' => '2026-10-06T13:30', 'atb' => '2026-10-06T14:00', 'atd' => '2026-10-06T18:00'])->assertOk()->json('data');

        // Port DAs (final) at the two port calls
        $dac = fn (string $code) => DaCostCategory::query()->where('code', $code)->value('id');
        $mkDa = function (array $call, array $items) use ($v, $base, $ops2) {
            $da = $this->postJson('/api/v1/port-das', ['voyage_id' => $v, 'port_call_id' => $call['id'], 'port_id' => $base->id, 'da_type' => 'final', 'currency' => 'USD'])->assertCreated()->json('data');
            $this->postJson("/api/v1/port-das/{$da['id']}/items", ['items' => $items])->assertOk();
            $this->postJson("/api/v1/port-das/{$da['id']}/submit")->assertOk();
            $this->as($ops2)->postJson("/api/v1/port-das/{$da['id']}/approve")->assertOk();
            $this->as($this->ops);
        };
        $mkDa($c1, [['da_cost_category_id' => $dac('PILOT'), 'description' => 'Pilotage', 'estimated_amount' => '1500', 'actual_amount' => '1800'],
            ['da_cost_category_id' => $dac('BERTH'), 'description' => 'Berth / dockage', 'estimated_amount' => '2500', 'actual_amount' => '2500'],
            ['da_cost_category_id' => $dac('AGENCY'), 'description' => 'Agency fee', 'estimated_amount' => '800', 'actual_amount' => '800']]);
        $mkDa($c3, [['da_cost_category_id' => $dac('PILOT'), 'description' => 'Pilotage', 'estimated_amount' => '1500', 'actual_amount' => '1200'],
            ['da_cost_category_id' => $dac('BERTH'), 'description' => 'Berth / dockage', 'estimated_amount' => '1500', 'actual_amount' => '1500'],
            ['da_cost_category_id' => $dac('AGENCY'), 'description' => 'Agency fee', 'estimated_amount' => '600', 'actual_amount' => '600']]);

        $this->postJson("/api/v1/voyages/{$v}/complete", ['at' => '2026-10-06T19:00'])->assertOk();

        // ---- 7. Finance
        $finance = $this->userWithRole(UserRole::Finance);
        $this->as($finance);
        $mkExp = fn (string $code, string $desc, array $money) => $this->postJson('/api/v1/voyage-expenses', ['voyage_id' => $v, 'contract_id' => $cid,
            'expense_category_id' => ExpenseCategory::query()->where('code', $code)->value('id'), 'description' => $desc, 'currency' => 'USD', ...$money])->assertCreated()->json('data');
        $lines = [
            $mkExp('BUNKER', 'Bunkers — 95 MT MGO @ 800 (BDN-0101)', ['quantity' => '95', 'rate' => '800', 'supplier_company_id' => $supplier->id, 'incurred_at' => '2026-09-23']),
            $mkExp('OPEX', 'Operational expense — crew, insurance, maintenance', ['amount' => '28000']),
        ];
        foreach ($lines as $e) {
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
        $rev = $this->getJson("/api/v1/voyage-revenues?voyage_id={$v}&status=confirmed&per_page=50")->json('data');
        $inv = $this->postJson('/api/v1/invoices', ['invoice_type' => 'offshore_service', 'customer_company_id' => $client->id, 'contract_id' => $cid, 'voyage_id' => $v,
            'issue_date' => '2026-10-07', 'due_date' => '2026-11-06', 'currency' => 'USD', 'revenue_ids' => collect($rev)->pluck('id')->all()])->assertCreated()->json('data');
        $this->assertSame(['203875.00', 7], [$inv['total'], count($inv['lines'])]);
        $inv = $this->postJson("/api/v1/invoices/{$inv['id']}/issue")->assertOk()->json('data');
        $pay = $this->postJson('/api/v1/payments', ['direction' => 'received', 'currency' => 'USD', 'company_id' => $client->id, 'payment_date' => '2026-10-09', 'amount' => $inv['total'],
            'method' => 'wire', 'bank_reference' => 'TT-ALPHA-01'])->assertCreated()->json('data');
        $this->postJson("/api/v1/payments/{$pay['id']}/allocate", ['allocations' => [['invoice_id' => $inv['id'], 'amount' => $inv['total']]]])->assertOk();
        $this->assertSame('paid', $this->getJson("/api/v1/invoices/{$inv['id']}")->json('data.status'));

        $payable = $this->postJson('/api/v1/payables', ['supplier_company_id' => $supplier->id, 'supplier_invoice_ref' => 'OF-INV-9021', 'currency' => 'USD',
            'issue_date' => '2026-09-23', 'due_date' => '2026-10-23', 'subtotal' => '76000', 'tax' => '0'])->assertCreated()->json('data');
        $this->as($this->commercial)->postJson("/api/v1/payables/{$payable['id']}/approve")->assertOk();
        $this->as($finance);
        $out = $this->postJson('/api/v1/payments', ['direction' => 'paid', 'currency' => 'USD', 'company_id' => $supplier->id, 'payment_date' => '2026-10-09', 'amount' => '76000',
            'method' => 'wire', 'bank_reference' => 'TT-OUT-7001'])->assertCreated()->json('data');
        $this->postJson("/api/v1/payments/{$out['id']}/allocate", ['allocations' => [['payable_id' => $payable['id'], 'amount' => '76000']]])->assertOk();
        $this->assertSame('paid', $this->getJson("/api/v1/payables/{$payable['id']}")->json('data.status'));

        // ---- 8. Finalize + P&L
        $gates = $this->as($this->ops)->getJson("/api/v1/voyages/{$v}/finance-gates")->json('data');
        $this->assertSame(['ledger' => true, 'revenue' => true, 'port_da' => true, 'laytime' => true], array_map(fn ($g) => $g['passed'], $gates));
        $this->postJson("/api/v1/voyages/{$v}/finalize")->assertOk()->assertJsonPath('data.status', 'finalized');

        $pnl = $this->as($finance)->getJson("/api/v1/voyages/{$v}/financials")->assertOk()->json('data');
        $rows = collect($pnl['rows'])->keyBy('metric');
        $this->assertSame(['199000.00', '203875.00', '4875.00'], [$rows['gross_revenue']['estimate'], $rows['gross_revenue']['actual'], $rows['gross_revenue']['variance']]);
        // The contract's 3.75 % commission is deducted automatically on the billable time lines (145,000 day rate + 4,875 ROV).
        $this->assertSame(['5437.50', '5620.31', '182.81'], [$rows['commission']['estimate'], $rows['commission']['actual'], $rows['commission']['variance']]);
        $this->assertSame(['98866.67', '112400.00', '13533.33'], [$rows['total_costs']['estimate'], $rows['total_costs']['actual'], $rows['total_costs']['variance']]);
        $this->assertSame(['94695.83', '85854.69', '-8841.14'], [$rows['net_profit']['estimate'], $rows['net_profit']['actual'], $rows['net_profit']['variance']]);
        $this->assertSame(['91475.00', '42.11', '6416.64'], [$pnl['gross_profit'], $pnl['margin_pct'], $pnl['profit_per_day']]);
        $this->assertSame(['3.36', '13.69', '-9.34'], [$rows['commission']['variance_pct'], $rows['total_costs']['variance_pct'], $rows['net_profit']['variance_pct']]);
        $this->assertSame(['Bunkers' => '76000.00', 'Operational expense' => '28000.00', 'Port charges' => '7000.00', 'Agency fees' => '1400.00'],
            collect($pnl['expense_by_category'])->pluck('amount', 'category')->all());

        $summary = $this->as($this->ops)->getJson("/api/v1/offshore-projects/{$project['id']}")->json('data.summary');
        $this->assertSame(['247.5000', '24.0000', '24.0000'], [$summary['billable_hours'], $summary['non_billable_hours'], $summary['standby_hours']]);
    }
}
