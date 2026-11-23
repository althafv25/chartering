<?php

namespace Tests\Feature\Finance;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\DaCostCategory;
use App\Models\ExchangeRate;
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
 * Backs docs/offshore-actual-cost-example.md: one offshore job whose ACTUAL costs and dates differ from the estimate in
 * several ways (more days, more fuel at a higher price, a higher port bill, an unplanned cost, a cost in AED, plus a cancelled
 * and a draft line that must not count). Every figure the document quotes is pinned here.
 */
class OffshoreActualCostExampleTest extends OperationsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-10 12:00:00');
    }

    public function test_actual_cost_is_built_from_confirmed_ledger_lines_and_compared_with_the_estimate(): void
    {
        $mgo = FuelType::query()->where('code', 'MGO')->value('id');
        $client = Company::query()->create(['code' => 'SMS', 'legal_name' => 'Sahara Marine Services LLC', 'normalized_name' => 'sahara marine services']);
        $client->roles()->create(['role' => 'charterer']);
        $supplier = Company::query()->create(['code' => 'EFS', 'legal_name' => 'Emirates Fuel Supply LLC', 'normalized_name' => 'emirates fuel supply']);
        $supplier->roles()->create(['role' => 'supplier']);
        ExchangeRate::query()->create(['rate_date' => '2026-10-01', 'base_currency' => 'USD', 'quote_currency' => 'AED', 'rate' => '3.67250000', 'source' => 'manual']);

        $base = Port::query()->find($this->ports[0]);
        $field = OffshoreLocation::query()->create(['code' => 'ZORA-1', 'name' => 'Zora Field', 'field_name' => 'Zora', 'latitude' => '25.1', 'longitude' => '55.0',
            'timezone' => 'Asia/Dubai', 'nearest_port_id' => $base->id]);
        PortDistance::query()->create(['from_type' => 'port', 'from_id' => $base->id, 'to_type' => 'location', 'to_id' => $field->id, 'distance_nm' => '100', 'provider' => 'manual', 'calculated_at' => now()]);
        $vessel = Vessel::query()->create(['code' => 'PSV03', 'name' => 'MV Coral Star', 'vessel_type_id' => VesselType::query()->where('code', 'PSV')->value('id'), 'service_speed_kn' => '12']);
        $profile = $vessel->consumptionProfiles()->create(['name' => 'Design', 'effective_from' => '2026-01-01', 'is_default' => true]);
        foreach ([['sea_laden', '12', '14'], ['sea_ballast', '12', '13'], ['port_working', '0', '3'], ['dp_operation', '0', '7'], ['standby', '0', '2']] as [$mode, $speed, $mt]) {
            $profile->rates()->create(['mode' => $mode, 'speed_kn' => $speed, 'fuel_type_id' => $mgo, 'consumption_mt_per_day' => $mt]);
        }

        // ---- Estimate
        $this->as($this->charterer);
        $enq = $this->postJson('/api/v1/enquiries', ['business_type' => 'offshore_charter', 'charterer_company_id' => $client->id, 'cargo_description' => 'Platform supply, Zora Field',
            'period_days' => '5', 'rate_idea' => '12000', 'rate_basis' => 'per_day', 'currency' => 'USD', 'laycan_from' => '2026-10-19', 'laycan_to' => '2026-10-20',
            'ports' => [['port_id' => $base->id, 'purpose' => 'load'], ['offshore_location_id' => $field->id, 'purpose' => 'offshore_ops'], ['port_id' => $base->id, 'purpose' => 'redelivery']],
        ])->assertCreated()->json('data.id');
        $res = $this->postJson('/api/v1/estimations', ['enquiry_id' => $enq, 'vessel_id' => $vessel->id])->assertCreated();
        $est = $res->json('data.id');
        $sc = $res->json('data.scenarios.0.id');
        $s = $this->getJson("/api/v1/estimations/{$est}/scenarios/{$sc}")->json('data');
        $in = $s['inputs'];
        $in['legs'][1]['condition'] = 'ballast';
        $in['fuel_prices'][0]['price_per_mt'] = '700';
        $in['calls'][0]['working_days'] = '1';
        $in['calls'][0]['port_cost']['amount'] = '3500';
        $in['calls'][0]['agency_cost']['amount'] = '500';
        $in['calls'][1]['dp_days'] = '5';
        $in['calls'][2]['working_days'] = '1';
        $in['calls'][2]['port_cost']['amount'] = '2500';
        $in['calls'][2]['agency_cost']['amount'] = '500';
        $in['revenue_items'][0]['quantity'] = '5';
        $in['revenue_items'][0]['address_pct'] = '2.5';
        $in['revenue_items'][0]['brokerage_pct'] = '1.25';
        foreach ([['MOBFEE', 'Mobilization fee', '18000'], ['DEMOBFEE', 'Demobilization fee', '15000']] as [$code, $d, $rate]) {
            $in['revenue_items'][] = ['revenue_category_id' => RevenueCategory::query()->where('code', $code)->value('id'), 'description' => $d, 'basis' => 'lump_sum', 'quantity' => null,
                'rate' => $rate, 'currency' => 'USD', 'fx_rate' => '1', 'commissionable' => false, 'address_pct' => '0', 'brokerage_pct' => '0', 'other_pct' => '0', 'primary' => false];
        }
        // Operating cost per day with the Days field left empty: the estimate uses ALL elapsed days.
        $in['cost_items'][] = ['expense_category_id' => ExpenseCategory::query()->where('code', 'OPEX')->value('id'), 'description' => 'Operational expense', 'basis' => 'per_day',
            'quantity' => null, 'rate' => '2000', 'currency' => 'USD', 'fx_rate' => '1', 'account' => 'owner'];
        $saved = $this->putJson("/api/v1/estimations/{$est}/scenarios/{$sc}", ['lock_version' => $s['lock_version'], 'inputs' => $in])->assertOk()->json('data');
        $this->assertSame('calculated', $saved['calc_status']);
        $r = $saved['result'];
        $this->postJson("/api/v1/estimations/{$est}/scenarios/{$sc}/select")->assertOk();
        $this->postJson("/api/v1/estimations/{$est}/submit")->assertOk();
        $this->as($this->manager)->postJson("/api/v1/estimations/{$est}/approve")->assertOk();

        // ---- Offer, fixture, contract
        $this->as($this->charterer);
        $offer = $this->postJson('/api/v1/offers', ['enquiry_id' => $enq, 'vessel_id' => $vessel->id, 'estimation_scenario_id' => $sc])->assertCreated()->json('data');
        $rev = $offer['revisions'][0]['id'];
        $this->putJson("/api/v1/offers/{$offer['id']}/revisions/{$rev}", ['valid_until' => '2026-10-17', 'terms' => 'Day rate charter.'])->assertOk();
        $this->postJson("/api/v1/offers/{$offer['id']}/revisions/{$rev}/send")->assertOk();
        $this->postJson("/api/v1/offers/{$offer['id']}/revisions/{$rev}/accept")->assertOk();
        $fx = $this->postJson("/api/v1/offers/{$offer['id']}/revisions/{$rev}/convert-to-fixture")->assertCreated()->json('data');
        $this->postJson("/api/v1/fixtures/{$fx['id']}/submit")->assertOk();
        $this->as($this->manager)->postJson("/api/v1/fixtures/{$fx['id']}/approve")->assertOk();
        $this->as($this->charterer);
        $contract = $this->postJson("/api/v1/fixtures/{$fx['id']}/convert-to-contract")->assertCreated()->json('data');
        $cid = $contract['id'];
        $contract = $this->putJson("/api/v1/contracts/{$cid}", ['lock_version' => $contract['lock_version'], 'title' => 'Zora Field supply support', 'end_date' => '2026-12-31', 'payment_terms_days' => 30])->assertOk()->json('data');
        $current = collect($contract['rates'])->map(fn ($x) => array_intersect_key($x, array_flip(['rate_type', 'amount', 'currency', 'unit', 'offshore_activity_type_id', 'description'])))->all();
        $rate = fn (string $type, string $amount, string $unit, string $desc) => ['rate_type' => $type, 'amount' => $amount, 'currency' => 'USD', 'unit' => $unit, 'offshore_activity_type_id' => null, 'description' => $desc];
        $this->putJson("/api/v1/contracts/{$cid}/rates", ['lock_version' => $contract['lock_version'], 'rates' => [
            ...$current, $rate('standby_day_rate', '7000', 'per_day', 'Standby'), $rate('mobilization_fee', '18000', 'lump_sum', 'Mobilization'),
            $rate('demobilization_fee', '15000', 'lump_sum', 'Demobilization'),
        ]])->assertOk();
        $this->postJson("/api/v1/contracts/{$cid}/submit")->assertOk();
        $this->as($this->commercial)->postJson("/api/v1/contracts/{$cid}/approve")->assertOk();
        $this->as($this->charterer)->postJson("/api/v1/contracts/{$cid}/activate")->assertOk();

        // ---- Voyage: actual dates 20 Oct 06:00 to 29 Oct 12:00 (Dubai) = 9 days 6 hours
        $ops2 = $this->userWithRole(UserRole::Operations);
        $this->as($this->ops);
        $voyage = $this->postJson("/api/v1/fixtures/{$fx['id']}/convert-to-voyage")->assertCreated()->json('data');
        $v = $voyage['id'];
        $project = $this->postJson('/api/v1/offshore-projects', ['code' => 'ZORA-2026', 'name' => 'Zora Field supply 2026', 'contract_id' => $cid, 'offshore_location_id' => $field->id])->assertCreated()->json('data');
        $move = fn (string $to, string $at) => $this->postJson("/api/v1/voyages/{$v}/transition", ['status' => $to, 'at' => $at])->assertOk();
        $move('nominated', '2026-10-19T16:00');
        $c1 = $this->postJson("/api/v1/voyages/{$v}/port-calls", ['port_id' => $base->id, 'purpose' => 'supply', 'eta' => '2026-10-20T04:00', 'ata' => '2026-10-20T05:00', 'atb' => '2026-10-20T06:00'])->assertCreated()->json('data');
        $c2 = $this->postJson("/api/v1/voyages/{$v}/port-calls", ['offshore_location_id' => $field->id, 'purpose' => 'offshore_ops', 'eta' => '2026-10-21T12:00'])->assertCreated()->json('data');
        $c3 = $this->postJson("/api/v1/voyages/{$v}/port-calls", ['port_id' => $base->id, 'purpose' => 'other', 'eta' => '2026-10-29T01:00'])->assertCreated()->json('data');
        $move('mobilizing', '2026-10-20T06:00');

        $stem = $this->postJson('/api/v1/bunker-stems', ['vessel_id' => $vessel->id, 'voyage_id' => $v, 'port_call_id' => $c1['id'], 'fuel_type_id' => $mgo,
            'supplier_company_id' => $supplier->id, 'ordered_on' => '2026-10-19', 'ordered_mt' => '56', 'price_per_mt' => '720', 'currency' => 'USD'])->assertCreated()->json('data');
        $stem = $this->postJson("/api/v1/bunker-stems/{$stem['id']}/deliver", ['lock_version' => $stem['lock_version'], 'delivered_at' => '2026-10-20T04:30', 'delivered_mt' => '56', 'bdn_number' => 'BDN-0401'])->assertOk()->json('data');
        $c1 = $this->putJson("/api/v1/voyages/{$v}/port-calls/{$c1['id']}", ['lock_version' => $c1['lock_version'], 'atd' => '2026-10-21T06:00'])->assertOk()->json('data');

        $act = function (string $type, string $from, string $to, string $desc, array $hours) use ($vessel, $v, $project, $field, $ops2): array {
            $a = $this->postJson('/api/v1/offshore-activities', ['vessel_id' => $vessel->id, 'voyage_id' => $v, 'offshore_project_id' => $project['id'], 'offshore_location_id' => $field->id,
                'offshore_activity_type_id' => OffshoreActivityType::query()->where('code', $type)->value('id'), 'description' => $desc, 'start_at' => $from, 'end_at' => $to, ...$hours])->assertCreated()->json('data');
            $this->postJson("/api/v1/offshore-activities/{$a['id']}/submit")->assertOk();
            $verified = $this->as($ops2)->postJson("/api/v1/offshore-activities/{$a['id']}/verify", ['comment' => 'OK'])->assertOk()->json('data');
            $this->as($this->ops);

            return $verified;
        };
        $acts = [];
        $acts['MOB'] = $act('MOB', '2026-10-20T06:00', '2026-10-20T16:00', 'Mobilization', ['billable_hours' => '0', 'non_billable_hours' => '10', 'standby_hours' => '0']);
        $move('offshore_operation', '2026-10-21T12:00');
        $c2 = $this->putJson("/api/v1/voyages/{$v}/port-calls/{$c2['id']}", ['lock_version' => $c2['lock_version'], 'ata' => '2026-10-21T12:00'])->assertOk()->json('data');
        $acts['S1'] = $act('SUPPLY', '2026-10-21T12:00', '2026-10-23T12:00', 'Supply run 1', ['billable_hours' => '48']);
        $acts['S2'] = $act('SUPPLY', '2026-10-23T12:00', '2026-10-27T12:00', 'Supply run 2', ['billable_hours' => '96']);
        $move('standby', '2026-10-27T12:00');
        $acts['SB'] = $act('STANDBY', '2026-10-27T12:00', '2026-10-28T00:00', 'Waiting on weather', ['billable_hours' => '0', 'standby_hours' => '12']);
        $move('demobilizing', '2026-10-28T08:00');
        $acts['DEMOB'] = $act('DEMOB', '2026-10-28T08:00', '2026-10-28T20:00', 'Demobilization', ['billable_hours' => '0', 'non_billable_hours' => '12', 'standby_hours' => '0']);
        $c2 = $this->putJson("/api/v1/voyages/{$v}/port-calls/{$c2['id']}", ['lock_version' => $c2['lock_version'], 'atd' => '2026-10-28T16:00'])->assertOk()->json('data');
        $c3 = $this->putJson("/api/v1/voyages/{$v}/port-calls/{$c3['id']}", ['lock_version' => $c3['lock_version'], 'ata' => '2026-10-29T01:00', 'atb' => '2026-10-29T02:00', 'atd' => '2026-10-29T10:00'])->assertOk()->json('data');

        $dac = fn (string $code) => DaCostCategory::query()->where('code', $code)->value('id');
        $mkDa = function (array $call, array $items) use ($v, $base, $ops2): void {
            $da = $this->postJson('/api/v1/port-das', ['voyage_id' => $v, 'port_call_id' => $call['id'], 'port_id' => $base->id, 'da_type' => 'final', 'currency' => 'USD'])->assertCreated()->json('data');
            $this->postJson("/api/v1/port-das/{$da['id']}/items", ['items' => $items])->assertOk();
            $this->postJson("/api/v1/port-das/{$da['id']}/submit")->assertOk();
            $this->as($ops2)->postJson("/api/v1/port-das/{$da['id']}/approve")->assertOk();
            $this->as($this->ops);
        };
        $mkDa($c1, [
            ['da_cost_category_id' => $dac('PILOT'), 'description' => 'Pilotage', 'estimated_amount' => '1500', 'actual_amount' => '1700'],
            ['da_cost_category_id' => $dac('BERTH'), 'description' => 'Berth / dockage', 'estimated_amount' => '2000', 'actual_amount' => '2000'],
            ['da_cost_category_id' => $dac('AGENCY'), 'description' => 'Agency fee', 'estimated_amount' => '500', 'actual_amount' => '550'],
        ]);
        $mkDa($c3, [
            ['da_cost_category_id' => $dac('PILOT'), 'description' => 'Pilotage', 'estimated_amount' => '1000', 'actual_amount' => '900'],
            ['da_cost_category_id' => $dac('BERTH'), 'description' => 'Berth / dockage', 'estimated_amount' => '1500', 'actual_amount' => '1500'],
            ['da_cost_category_id' => $dac('AGENCY'), 'description' => 'Agency fee', 'estimated_amount' => '500', 'actual_amount' => '500'],
        ]);
        $this->postJson("/api/v1/voyages/{$v}/complete", ['at' => '2026-10-29T12:00'])->assertOk();

        // ---- Costs entered by hand
        $finance = $this->userWithRole(UserRole::Finance);
        $this->as($finance);
        $cat = fn (string $code) => ExpenseCategory::query()->where('code', $code)->value('id');
        $line = fn (string $code, string $desc, array $money) => $this->postJson('/api/v1/voyage-expenses', ['voyage_id' => $v, 'contract_id' => $cid, 'expense_category_id' => $cat($code),
            'description' => $desc, 'currency' => 'USD', ...$money])->assertCreated()->json('data');
        $bunker = $line('BUNKER', 'Bunkers, 56 MT MGO @ 720 (BDN-0401)', ['quantity' => '56', 'rate' => '720', 'supplier_company_id' => $supplier->id, 'incurred_at' => '2026-10-20']);
        $opex = $line('OPEX', 'Operational expense, 9.25 days x 2,000', ['quantity' => '9.25', 'rate' => '2000', 'incurred_at' => '2026-10-29']);
        $tug = $line('OTHER', 'Tug assistance at Zora Field (not planned)', ['amount' => '3000', 'incurred_at' => '2026-10-24']);
        $aed = $line('AGENCY', 'Local clearance fee, AED 3,672.50', ['amount' => '3672.50', 'currency' => 'AED', 'incurred_at' => '2026-10-20']);
        $duplicate = $line('OPEX', 'Operational expense (duplicate entry)', ['quantity' => '9.25', 'rate' => '2000', 'incurred_at' => '2026-10-29']);
        $draft = $line('OTHER', 'Supplier late-payment claim (not agreed yet)', ['amount' => '2000', 'incurred_at' => '2026-10-30']);
        $this->assertSame(['40320.00', '18500.00', '3000.00', '3672.50', '18500.00', '2000.00'], [$bunker['amount'], $opex['amount'], $tug['amount'], $aed['amount'], $duplicate['amount'], $draft['amount']]);
        $this->assertSame('1000.00', $aed['base_amount']);

        foreach ([$bunker, $opex, $tug, $aed] as $e) {
            $this->postJson("/api/v1/voyage-expenses/{$e['id']}/confirm")->assertOk();
            $this->as($this->commercial)->postJson("/api/v1/voyage-expenses/{$e['id']}/approve")->assertOk();
            $this->as($finance);
        }
        $this->postJson("/api/v1/voyage-expenses/{$duplicate['id']}/cancel", ['reason' => 'Duplicate of the operational expense line'])->assertOk();
        $this->as($this->commercial);
        foreach ($this->getJson("/api/v1/voyage-expenses?voyage_id={$v}&per_page=50")->json('data') as $e) {
            if ($e['status'] === 'confirmed') {
                $this->postJson("/api/v1/voyage-expenses/{$e['id']}/approve")->assertOk();
            }
        }

        // ---- Read the ledger and the P&L
        $this->as($finance);
        $ledger = collect($this->getJson("/api/v1/voyage-expenses?voyage_id={$v}&per_page=50")->json('data'))
            ->map(fn ($e) => [$e['description'], $e['currency'], $e['amount'], $e['base_amount'], $e['status']])->sortBy(0)->values()->all();
        $pnl = $this->getJson("/api/v1/voyages/{$v}/financials")->assertOk()->json('data');
        $rows = collect($pnl['rows'])->keyBy('metric');
        // Estimate (initial snapshot)
        $this->assertSame(['7.694444', '50.375', '35262.50', '6000.00', '1000.00', '15388.89', '57651.39', '93000.00', '2250.00', '33098.61'],
            [$r['total_days'], $r['fuel_total_mt'], $r['fuel_cost'], $r['port_costs'], $r['agency_costs'], $r['operational_costs'], $r['total_costs'], $r['gross_revenue'], $r['total_commission'], $r['profit']]);
        // Activity revenue
        $this->assertSame(['18000.00', '24000.00', '48000.00', '3500.00', '15000.00'], array_values(array_map(fn ($a) => $a['revenue_amount'], $acts)));
        // Ledger: the cancelled duplicate and the draft claim are present but do not count
        $this->assertContains(['Operational expense (duplicate entry)', 'USD', '18500.00', '18500.00', 'cancelled'], $ledger);
        $this->assertContains(['Supplier late-payment claim (not agreed yet)', 'USD', '2000.00', '2000.00', 'draft'], $ledger);
        $this->assertSame(['Bunkers' => '40320.00', 'Operational expense' => '18500.00', 'Port charges' => '6100.00', 'Other expense' => '3000.00', 'Agency fees' => '2050.00'],
            collect($pnl['expense_by_category'])->pluck('amount', 'category')->all());
        // Actual against estimate
        $this->assertSame(['93000.00', '108500.00', '15500.00', '16.67'], [$rows['gross_revenue']['estimate'], $rows['gross_revenue']['actual'], $rows['gross_revenue']['variance'], $rows['gross_revenue']['variance_pct']]);
        $this->assertSame(['2250.00', '2700.00', '450.00', '20.00'], [$rows['commission']['estimate'], $rows['commission']['actual'], $rows['commission']['variance'], $rows['commission']['variance_pct']]);
        $this->assertSame(['57651.39', '69970.00', '12318.61', '21.37'], [$rows['total_costs']['estimate'], $rows['total_costs']['actual'], $rows['total_costs']['variance'], $rows['total_costs']['variance_pct']]);
        $this->assertSame(['33098.61', '35830.00', '2731.39', '8.25'], [$rows['net_profit']['estimate'], $rows['net_profit']['actual'], $rows['net_profit']['variance'], $rows['net_profit']['variance_pct']]);
        $this->assertSame(['38530.00', '33.02', '3873.51', '9.25'], [$pnl['gross_profit'], $pnl['margin_pct'], $pnl['profit_per_day'], $pnl['total_days']]);
    }
}
