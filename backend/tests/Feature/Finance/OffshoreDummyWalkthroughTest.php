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
 * Replays docs/offshore-dummy-walkthrough.md: a second, independent offshore day-rate job with its own dummy data
 * (client Desert Rock Energy LLC, MV Northern Star, Hala Field), from enquiry to finalized P&L.
 * Every figure the document quotes is pinned here. If a calculation changes, this test and the document change together.
 */
class OffshoreDummyWalkthroughTest extends OperationsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-10 12:00:00');
    }

    public function test_offshore_day_rate_job_from_enquiry_to_final_profit(): void
    {
        $mgo = FuelType::query()->where('code', 'MGO')->value('id');
        $client = Company::query()->create(['code' => 'DRE', 'legal_name' => 'Desert Rock Energy LLC', 'normalized_name' => 'desert rock energy']);
        $client->roles()->create(['role' => 'charterer']);
        $owner = Company::query()->create(['code' => 'NSL', 'legal_name' => 'Northern Shipping LLC', 'normalized_name' => 'northern shipping']);
        $owner->roles()->create(['role' => 'owner']);
        $supplier = Company::query()->create(['code' => 'GFS', 'legal_name' => 'Gulf Fuel Supply FZE', 'normalized_name' => 'gulf fuel supply']);
        $supplier->roles()->create(['role' => 'supplier']);

        $base = Port::query()->find($this->ports[0]); // Jebel Ali
        $field = OffshoreLocation::query()->create(['code' => 'HALA-1', 'name' => 'Hala Field', 'field_name' => 'Hala', 'latitude' => '24.9', 'longitude' => '54.7',
            'timezone' => 'Asia/Dubai', 'nearest_port_id' => $base->id]);
        PortDistance::query()->create(['from_type' => 'port', 'from_id' => $base->id, 'to_type' => 'location', 'to_id' => $field->id, 'distance_nm' => '100',
            'provider' => 'manual', 'calculated_at' => now()]);

        $vessel = Vessel::query()->create(['code' => 'PSV02', 'name' => 'MV Northern Star', 'vessel_type_id' => VesselType::query()->where('code', 'PSV')->value('id'),
            'owner_company_id' => $owner->id, 'service_speed_kn' => '12']);
        $profile = $vessel->consumptionProfiles()->create(['name' => 'Design', 'effective_from' => '2026-01-01', 'is_default' => true]);
        foreach ([['sea_laden', '12', '14'], ['sea_ballast', '12', '13'], ['port_working', '0', '3'], ['dp_operation', '0', '7'], ['standby', '0', '2']] as [$mode, $speed, $mt]) {
            $profile->rates()->create(['mode' => $mode, 'speed_kn' => $speed, 'fuel_type_id' => $mgo, 'consumption_mt_per_day' => $mt]);
        }

        // ---- 1. Enquiry
        $this->as($this->charterer);
        $enq = $this->postJson('/api/v1/enquiries', [
            'business_type' => 'offshore_charter', 'source' => 'direct', 'charterer_company_id' => $client->id,
            'cargo_description' => 'Platform supply and ROV support, Hala Field', 'period_days' => '8', 'rate_idea' => '12000', 'rate_basis' => 'per_day',
            'currency' => 'USD', 'laycan_from' => '2026-09-27', 'laycan_to' => '2026-09-28', 'commission_terms' => '2.5% address + 1.25% brokerage',
            'ports' => [['port_id' => $base->id, 'purpose' => 'load'], ['offshore_location_id' => $field->id, 'purpose' => 'offshore_ops'], ['port_id' => $base->id, 'purpose' => 'redelivery']],
        ])->assertCreated()->json('data');
        $this->assertSame('ENQ', substr($enq['enquiry_number'], 0, 3));
        $enqId = $enq['id'];

        // ---- 2. Estimation
        $res = $this->postJson('/api/v1/estimations', ['enquiry_id' => $enqId, 'vessel_id' => $vessel->id])->assertCreated();
        $est = $res->json('data.id');
        $this->assertSame('offshore_day_rate', $res->json('data.estimation_type'));
        $sc = $res->json('data.scenarios.0.id');
        $s = $this->getJson("/api/v1/estimations/{$est}/scenarios/{$sc}")->json('data');
        $in = $s['inputs'];
        $in['legs'][1]['condition'] = 'ballast';
        $in['fuel_prices'][0]['price_per_mt'] = '750';
        $in['calls'][0]['working_days'] = '1';
        $in['calls'][0]['port_cost']['amount'] = '4000';
        $in['calls'][0]['agency_cost']['amount'] = '700';
        $in['calls'][1]['dp_days'] = '6';
        $in['calls'][1]['standby_days'] = '1';
        $in['calls'][2]['working_days'] = '1';
        $in['calls'][2]['port_cost']['amount'] = '2500';
        $in['calls'][2]['agency_cost']['amount'] = '500';
        $in['revenue_items'][0]['quantity'] = '6';
        $in['revenue_items'][0]['address_pct'] = '2.5';
        $in['revenue_items'][0]['brokerage_pct'] = '1.25';
        $cat = fn (string $cls, string $code) => $cls::query()->where('code', $code)->first();
        foreach ([['STANDBY', 'Standby day rate', 'per_day', '1', '7000'], ['MOBFEE', 'Mobilization fee', 'lump_sum', null, '18000'], ['DEMOBFEE', 'Demobilization fee', 'lump_sum', null, '15000']] as [$code, $d, $basis, $qty, $rate]) {
            $in['revenue_items'][] = ['revenue_category_id' => $cat(RevenueCategory::class, $code)->id, 'description' => $d, 'basis' => $basis, 'quantity' => $qty,
                'rate' => $rate, 'currency' => 'USD', 'fx_rate' => '1', 'commissionable' => false, 'address_pct' => '0', 'brokerage_pct' => '0', 'other_pct' => '0', 'primary' => false];
        }
        $in['cost_items'][] = ['expense_category_id' => $cat(ExpenseCategory::class, 'OPEX')->id, 'description' => 'Operational expense', 'basis' => 'per_day',
            'quantity' => '10', 'rate' => '2200', 'currency' => 'USD', 'fx_rate' => '1', 'account' => 'owner'];
        $saved = $this->putJson("/api/v1/estimations/{$est}/scenarios/{$sc}", ['lock_version' => $s['lock_version'], 'inputs' => $in])->assertOk()->json('data');
        $this->assertSame('calculated', $saved['calc_status']);
        $r = $saved['result'];
        $this->postJson("/api/v1/estimations/{$est}/scenarios/{$sc}/select")->assertOk();
        $this->postJson("/api/v1/estimations/{$est}/submit")->assertOk();
        $this->as($this->manager)->postJson("/api/v1/estimations/{$est}/approve")->assertOk();

        // ---- 3. Offer
        $this->as($this->charterer);
        $offer = $this->postJson('/api/v1/offers', ['enquiry_id' => $enqId, 'vessel_id' => $vessel->id, 'estimation_scenario_id' => $sc])->assertCreated()->json('data');
        $rev = $offer['revisions'][0]['id'];
        $this->putJson("/api/v1/offers/{$offer['id']}/revisions/{$rev}", ['valid_until' => '2026-09-25', 'terms' => 'Day rate charter. Fuel for owner account. Mob/demob lump sums apply.'])->assertOk();
        $this->postJson("/api/v1/offers/{$offer['id']}/revisions/{$rev}/send")->assertOk();
        $this->postJson("/api/v1/offers/{$offer['id']}/revisions/{$rev}/accept", ['note' => 'Accepted by email'])->assertOk();

        // ---- 4. Fixture and contract
        $fx = $this->postJson("/api/v1/offers/{$offer['id']}/revisions/{$rev}/convert-to-fixture")->assertCreated()->json('data');
        $this->postJson("/api/v1/fixtures/{$fx['id']}/submit")->assertOk();
        $this->as($this->manager)->postJson("/api/v1/fixtures/{$fx['id']}/approve")->assertOk();
        $this->as($this->charterer);
        $contract = $this->postJson("/api/v1/fixtures/{$fx['id']}/convert-to-contract")->assertCreated()->json('data');
        $cid = $contract['id'];
        $contract = $this->putJson("/api/v1/contracts/{$cid}", ['lock_version' => $contract['lock_version'], 'title' => 'Hala Field supply and ROV support 2026',
            'end_date' => '2026-12-31', 'payment_terms_days' => 30])->assertOk()->json('data');
        $current = collect($contract['rates'])->map(fn ($x) => array_intersect_key($x, array_flip(['rate_type', 'amount', 'currency', 'unit', 'offshore_activity_type_id', 'description', 'effective_from', 'effective_to'])))->all();
        $rate = fn (string $type, string $amount, string $unit, ?string $activity, string $desc) => ['rate_type' => $type, 'amount' => $amount, 'currency' => 'USD', 'unit' => $unit,
            'offshore_activity_type_id' => $activity ? OffshoreActivityType::query()->where('code', $activity)->value('id') : null, 'description' => $desc];
        $this->putJson("/api/v1/contracts/{$cid}/rates", ['lock_version' => $contract['lock_version'], 'rates' => [
            ...$current,
            $rate('standby_day_rate', '7000', 'per_day', null, 'Standby (weather and waiting)'),
            $rate('mobilization_fee', '18000', 'lump_sum', null, 'Mobilization'),
            $rate('demobilization_fee', '15000', 'lump_sum', null, 'Demobilization'),
            $rate('hourly', '500', 'per_hour', 'ROV', 'ROV support hourly'),
        ]])->assertOk();
        $this->postJson("/api/v1/contracts/{$cid}/submit")->assertOk();
        $this->as($this->commercial)->postJson("/api/v1/contracts/{$cid}/approve")->assertOk();
        $this->as($this->charterer)->postJson("/api/v1/contracts/{$cid}/activate")->assertOk();

        // ---- 5. Voyage, project and port calls
        $ops2 = $this->userWithRole(UserRole::Operations);
        $this->as($this->ops);
        $voyage = $this->postJson("/api/v1/fixtures/{$fx['id']}/convert-to-voyage")->assertCreated()->json('data');
        $v = $voyage['id'];
        $this->assertSame(['offshore', $cid], [$voyage['operation_type'], $voyage['contract_id']]);
        $project = $this->postJson('/api/v1/offshore-projects', ['code' => 'HALA-2026', 'name' => 'Hala Field supply and ROV 2026', 'contract_id' => $cid,
            'offshore_location_id' => $field->id, 'field_name' => 'Hala', 'start_date' => '2026-09-27', 'end_date' => '2026-12-31'])->assertCreated()->json('data');
        $move = fn (string $to, string $at) => $this->postJson("/api/v1/voyages/{$v}/transition", ['status' => $to, 'at' => $at])->assertOk();
        $move('nominated', '2026-09-27T16:00');

        $c1 = $this->postJson("/api/v1/voyages/{$v}/port-calls", ['port_id' => $base->id, 'purpose' => 'supply', 'eta' => '2026-09-28T04:00',
            'ata' => '2026-09-28T05:00', 'atb' => '2026-09-28T06:00'])->assertCreated()->json('data');
        $c2 = $this->postJson("/api/v1/voyages/{$v}/port-calls", ['offshore_location_id' => $field->id, 'purpose' => 'offshore_ops', 'eta' => '2026-09-29T14:00'])->assertCreated()->json('data');
        $c3 = $this->postJson("/api/v1/voyages/{$v}/port-calls", ['port_id' => $base->id, 'purpose' => 'other', 'eta' => '2026-10-07T11:00'])->assertCreated()->json('data');
        $move('mobilizing', '2026-09-28T06:00');

        // ---- 6. Bunkers at Jebel Ali
        $stem = $this->postJson('/api/v1/bunker-stems', ['vessel_id' => $vessel->id, 'voyage_id' => $v, 'port_call_id' => $c1['id'], 'fuel_type_id' => $mgo,
            'supplier_company_id' => $supplier->id, 'ordered_on' => '2026-09-27', 'ordered_mt' => '60', 'price_per_mt' => '750', 'currency' => 'USD'])->assertCreated()->json('data');
        $stem = $this->postJson("/api/v1/bunker-stems/{$stem['id']}/deliver", ['lock_version' => $stem['lock_version'], 'delivered_at' => '2026-09-28T04:30',
            'delivered_mt' => '60', 'bdn_number' => 'BDN-0301'])->assertOk()->json('data');
        $this->assertSame('45000.00', $stem['total_amount']);

        $c1 = $this->putJson("/api/v1/voyages/{$v}/port-calls/{$c1['id']}", ['lock_version' => $c1['lock_version'], 'atd' => '2026-09-29T06:00'])->assertOk()->json('data');

        // ---- 7. Activities (each one: create, submit, verify by a second user)
        $project = $this->getJson("/api/v1/offshore-projects/{$project['id']}")->json('data');
        $act = function (string $type, string $from, string $to, string $desc, array $hours) use ($vessel, $v, $project, $field, $ops2): array {
            $typeId = OffshoreActivityType::query()->where('code', $type)->value('id');
            $a = $this->postJson('/api/v1/offshore-activities', ['vessel_id' => $vessel->id, 'voyage_id' => $v, 'offshore_project_id' => $project['id'],
                'offshore_location_id' => $field->id, 'offshore_activity_type_id' => $typeId, 'description' => $desc, 'start_at' => $from, 'end_at' => $to, ...$hours])
                ->assertCreated()->json('data');
            $this->postJson("/api/v1/offshore-activities/{$a['id']}/submit")->assertOk();
            $verified = $this->as($ops2)->postJson("/api/v1/offshore-activities/{$a['id']}/verify", ['comment' => 'OK'])->assertOk()->json('data');
            $this->as($this->ops);

            return $verified;
        };
        $acts = [];
        $acts['MOB'] = $act('MOB', '2026-09-28T06:00', '2026-09-28T16:00', 'Mobilization at Jebel Ali', ['billable_hours' => '0', 'non_billable_hours' => '10', 'standby_hours' => '0']);
        $move('offshore_operation', '2026-09-29T14:00');
        $c2 = $this->putJson("/api/v1/voyages/{$v}/port-calls/{$c2['id']}", ['lock_version' => $c2['lock_version'], 'ata' => '2026-09-29T14:00'])->assertOk()->json('data');
        $acts['S1'] = $act('SUPPLY', '2026-09-29T06:00', '2026-09-30T06:00', 'Supply run 1', ['billable_hours' => '24']);
        $acts['ROV'] = $act('ROV', '2026-09-30T06:00', '2026-09-30T14:00', 'ROV inspection, subsea manifold', ['billable_hours' => '8']);
        $acts['S2'] = $act('SUPPLY', '2026-09-30T14:00', '2026-10-02T14:00', 'Supply run 2', ['billable_hours' => '48']);
        $move('standby', '2026-10-02T14:00');
        $acts['SB'] = $act('STANDBY', '2026-10-02T14:00', '2026-10-03T14:00', 'Waiting on weather', ['billable_hours' => '0', 'standby_hours' => '24']);
        $move('offshore_operation', '2026-10-03T14:00');
        $acts['S3'] = $act('SUPPLY', '2026-10-03T14:00', '2026-10-06T14:00', 'Supply run 3', ['billable_hours' => '72']);
        $move('demobilizing', '2026-10-06T14:00');
        $acts['DEMOB'] = $act('DEMOB', '2026-10-06T14:00', '2026-10-07T02:00', 'Demobilization', ['billable_hours' => '0', 'non_billable_hours' => '12', 'standby_hours' => '0']);

        $expectedRevenue = ['MOB' => '18000.00', 'S1' => '12000.00', 'ROV' => '4000.00', 'S2' => '24000.00', 'SB' => '7000.00', 'S3' => '36000.00', 'DEMOB' => '15000.00'];
        foreach ($expectedRevenue as $key => $amount) {
            $this->assertSame($amount, $acts[$key]['revenue_amount'], "activity {$key}");
        }

        // ---- 7b. Port DAs (final) at the two Jebel Ali calls
        $dac = fn (string $code) => DaCostCategory::query()->where('code', $code)->value('id');
        $makeDa = function (array $call, array $items) use ($v, $base, $ops2): void {
            $da = $this->postJson('/api/v1/port-das', ['voyage_id' => $v, 'port_call_id' => $call['id'], 'port_id' => $base->id, 'da_type' => 'final', 'currency' => 'USD'])->assertCreated()->json('data');
            $this->postJson("/api/v1/port-das/{$da['id']}/items", ['items' => $items])->assertOk();
            $this->postJson("/api/v1/port-das/{$da['id']}/submit")->assertOk();
            $this->as($ops2)->postJson("/api/v1/port-das/{$da['id']}/approve")->assertOk();
            $this->as($this->ops);
        };
        $makeDa($c1, [
            ['da_cost_category_id' => $dac('PILOT'), 'description' => 'Pilotage', 'estimated_amount' => '1500', 'actual_amount' => '1600'],
            ['da_cost_category_id' => $dac('BERTH'), 'description' => 'Berth / dockage', 'estimated_amount' => '2500', 'actual_amount' => '2400'],
            ['da_cost_category_id' => $dac('AGENCY'), 'description' => 'Agency fee', 'estimated_amount' => '700', 'actual_amount' => '700'],
        ]);
        $makeDa($c3, [
            ['da_cost_category_id' => $dac('PILOT'), 'description' => 'Pilotage', 'estimated_amount' => '1000', 'actual_amount' => '900'],
            ['da_cost_category_id' => $dac('BERTH'), 'description' => 'Berth / dockage', 'estimated_amount' => '1500', 'actual_amount' => '1600'],
            ['da_cost_category_id' => $dac('AGENCY'), 'description' => 'Agency fee', 'estimated_amount' => '500', 'actual_amount' => '500'],
        ]);

        $c2 = $this->putJson("/api/v1/voyages/{$v}/port-calls/{$c2['id']}", ['lock_version' => $c2['lock_version'], 'atd' => '2026-10-07T03:00'])->assertOk()->json('data');
        $c3 = $this->putJson("/api/v1/voyages/{$v}/port-calls/{$c3['id']}", ['lock_version' => $c3['lock_version'], 'ata' => '2026-10-07T11:00', 'atb' => '2026-10-07T12:00', 'atd' => '2026-10-07T14:00'])->assertOk()->json('data');
        $this->postJson("/api/v1/voyages/{$v}/complete", ['at' => '2026-10-07T16:00'])->assertOk();

        // ---- 8. Finance
        $finance = $this->userWithRole(UserRole::Finance);
        $this->as($finance);
        $bunker = $this->postJson('/api/v1/voyage-expenses', ['voyage_id' => $v, 'contract_id' => $cid, 'expense_category_id' => ExpenseCategory::query()->where('code', 'BUNKER')->value('id'),
            'description' => 'Bunkers, 60 MT MGO @ 750 (BDN-0301)', 'quantity' => '60', 'rate' => '750', 'currency' => 'USD', 'supplier_company_id' => $supplier->id, 'incurred_at' => '2026-09-28'])
            ->assertCreated()->json('data');
        $opex = $this->postJson('/api/v1/voyage-expenses', ['voyage_id' => $v, 'contract_id' => $cid, 'expense_category_id' => ExpenseCategory::query()->where('code', 'OPEX')->value('id'),
            'description' => 'Operational expense, crew, insurance and maintenance (10 days x 2,200)', 'amount' => '22000', 'currency' => 'USD', 'incurred_at' => '2026-10-07'])
            ->assertCreated()->json('data');
        foreach ([$bunker, $opex] as $e) {
            $this->postJson("/api/v1/voyage-expenses/{$e['id']}/confirm")->assertOk();
            $this->as($this->commercial)->postJson("/api/v1/voyage-expenses/{$e['id']}/approve")->assertOk();
            $this->as($finance);
        }

        $revIds = collect($this->getJson("/api/v1/voyage-revenues?voyage_id={$v}&status=confirmed&per_page=50")->json('data'))->pluck('id')->all();
        $this->assertCount(7, $revIds);
        $inv = $this->postJson('/api/v1/invoices', ['invoice_type' => 'offshore_service', 'customer_company_id' => $client->id, 'contract_id' => $cid, 'voyage_id' => $v,
            'issue_date' => '2026-10-08', 'due_date' => '2026-11-07', 'currency' => 'USD', 'revenue_ids' => $revIds])->assertCreated()->json('data');
        $inv = $this->postJson("/api/v1/invoices/{$inv['id']}/issue")->assertOk()->json('data');
        $this->assertSame(['116000.00', 'issued'], [$inv['total'], $inv['status']]);

        $pay = $this->postJson('/api/v1/payments', ['direction' => 'received', 'currency' => 'USD', 'company_id' => $client->id, 'payment_date' => '2026-10-09',
            'amount' => '116000', 'method' => 'wire', 'bank_reference' => 'TT-DRE-01'])->assertCreated()->json('data');
        $this->postJson("/api/v1/payments/{$pay['id']}/allocate", ['allocations' => [['invoice_id' => $inv['id'], 'amount' => '116000']]])->assertOk();

        $payable = $this->postJson('/api/v1/payables', ['supplier_company_id' => $supplier->id, 'supplier_invoice_ref' => 'GFS-INV-3301', 'currency' => 'USD',
            'issue_date' => '2026-09-28', 'due_date' => '2026-10-28', 'subtotal' => '45000', 'tax' => '0'])->assertCreated()->json('data');
        $this->as($this->commercial)->postJson("/api/v1/payables/{$payable['id']}/approve")->assertOk();
        $this->as($finance);
        $out = $this->postJson('/api/v1/payments', ['direction' => 'paid', 'currency' => 'USD', 'company_id' => $supplier->id, 'payment_date' => '2026-10-09',
            'amount' => '45000', 'method' => 'wire', 'bank_reference' => 'TT-OUT-3301'])->assertCreated()->json('data');
        $this->postJson("/api/v1/payments/{$out['id']}/allocate", ['allocations' => [['payable_id' => $payable['id'], 'amount' => '45000']]])->assertOk();

        // ---- 9. Finalize and P&L
        $gates = $this->as($this->ops)->getJson("/api/v1/voyages/{$v}/finance-gates")->json('data');
        $this->assertSame(['ledger' => true, 'revenue' => true, 'port_da' => true, 'laytime' => true], array_map(fn ($g) => $g['passed'], $gates));
        $this->postJson("/api/v1/voyages/{$v}/finalize")->assertOk()->assertJsonPath('data.status', 'finalized');

        $pnl = $this->as($finance)->getJson("/api/v1/voyages/{$v}/financials")->assertOk()->json('data');
        $rows = collect($pnl['rows'])->keyBy('metric');

        $this->assertSame(['9.694444', '59.375', '44531.25', '52231.25', '74231.25', '112000.00', '2700.00', '35068.75'],
            [$r['total_days'], $r['fuel_total_mt'], $r['fuel_cost'], $r['voyage_costs'], $r['total_costs'], $r['gross_revenue'], $r['total_commission'], $r['profit']]);
        $this->assertSame(['112000.00', '116000.00', '4000.00'], [$rows['gross_revenue']['estimate'], $rows['gross_revenue']['actual'], $rows['gross_revenue']['variance']]);
        $this->assertSame(['74231.25', '74700.00', '468.75', '0.63'], [$rows['total_costs']['estimate'], $rows['total_costs']['actual'], $rows['total_costs']['variance'], $rows['total_costs']['variance_pct']]);
        $this->assertSame(['35068.75', '38450.00', '3381.25', '9.64'], [$rows['net_profit']['estimate'], $rows['net_profit']['actual'], $rows['net_profit']['variance'], $rows['net_profit']['variance_pct']]);
        $this->assertSame(['41300.00', '33.15'], [$pnl['gross_profit'], $pnl['margin_pct']]);

        $this->assertSame(['Bunkers' => '45000.00', 'Operational expense' => '22000.00', 'Port charges' => '6500.00', 'Agency fees' => '1200.00'],
            collect($pnl['expense_by_category'])->pluck('amount', 'category')->all());
        $this->assertSame(['invoice' => 'INV-2026-00001', 'total' => '116000.00'], ['invoice' => $inv['invoice_number'], 'total' => $inv['total']]);

        $figures = [
            'enquiry' => $enq['enquiry_number'],
            'estimate' => [
                'sea_distance_nm' => $r['sea_distance_nm'], 'sea_days' => $r['sea_days'], 'port_days' => $r['port_days'], 'total_days' => $r['total_days'],
                'fuel_total_mt' => $r['fuel_total_mt'], 'fuel_cost' => $r['fuel_cost'], 'port_costs' => $r['port_costs'], 'agency_costs' => $r['agency_costs'],
                'voyage_costs' => $r['voyage_costs'], 'operational_costs' => $r['operational_costs'], 'total_costs' => $r['total_costs'],
                'gross_revenue' => $r['gross_revenue'], 'total_commission' => $r['total_commission'], 'net_revenue' => $r['net_revenue'],
                'profit' => $r['profit'], 'margin' => $r['profit_margin_pct'] ?? null, 'tce_per_day' => $r['tce_per_day'], 'breakeven_rate' => $r['breakeven_rate'],
            ],
            'activities' => collect($acts)->map(fn ($a) => [$a['activity_number'], $a['revenue_amount'], $a['billable_hours'], $a['non_billable_hours'], $a['standby_hours']])->all(),
            'invoice' => [$inv['invoice_number'], $inv['total']],
            'pnl' => [
                'gross_revenue' => [$rows['gross_revenue']['estimate'], $rows['gross_revenue']['actual'], $rows['gross_revenue']['variance']],
                'commission' => [$rows['commission']['estimate'], $rows['commission']['actual'], $rows['commission']['variance']],
                'total_costs' => [$rows['total_costs']['estimate'], $rows['total_costs']['actual'], $rows['total_costs']['variance'], $rows['total_costs']['variance_pct']],
                'net_profit' => [$rows['net_profit']['estimate'], $rows['net_profit']['actual'], $rows['net_profit']['variance'], $rows['net_profit']['variance_pct']],
                'gross_profit' => $pnl['gross_profit'], 'margin_pct' => $pnl['margin_pct'], 'profit_per_day' => $pnl['profit_per_day'], 'total_days' => $pnl['total_days'],
            ],
            'project' => $this->getJson("/api/v1/offshore-projects/{$project['id']}")->json('data.summary'),
        ];
        file_put_contents('/tmp/offshore-dummy-figures.json', json_encode($figures, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->addToAssertionCount(1);
    }
}
