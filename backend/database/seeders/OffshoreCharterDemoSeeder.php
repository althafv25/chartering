<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Company;
use App\Models\DaCostCategory;
use App\Models\ExpenseCategory;
use App\Models\FuelType;
use App\Models\OffshoreActivityType;
use App\Models\OffshoreLocation;
use App\Models\Port;
use App\Models\PortDistance;
use App\Models\RevenueCategory;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselType;
use App\Support\NameNormalizer;
use Database\Seeders\Concerns\DrivesApi;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * One complete offshore day-rate charter, from enquiry to a finalized voyage with its P&L, built with the real workflow.
 *
 *   php artisan db:seed --class=OffshoreCharterDemoSeeder
 *
 * Data: client Desert Rock Energy LLC, MV Northern Star, Hala Field, USD 12,000 per day. It is the job described in
 * docs/offshore-dummy-walkthrough.md. Every step goes through the application's own API (same validation, workflow and
 * maker-checker rules as the screens), as user A (admin@offshore.local) and user B (checker@offshore.local, created if missing).
 *
 * Nothing is stored unless every step and the headline figures succeed (one transaction). Running it again does nothing.
 * It is NOT part of DatabaseSeeder and refuses to run in production.
 *
 * Open Voyage PSV02-… → P&L tab to see: estimated profit 35,068.75, actual net profit 38,450.00 (33.15 %).
 */
class OffshoreCharterDemoSeeder extends Seeder
{
    use DrivesApi;

    private User $a;

    private User $b;

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('The offshore charter demo data is for local and demo environments only.');
        }
        if (Vessel::query()->where('code', 'PSV02')->exists() || Company::query()->where('legal_name', 'Desert Rock Energy LLC')->exists()) {
            $this->command?->warn('The offshore charter demo already exists (vessel PSV02 or company Desert Rock Energy LLC). Nothing to do.');

            return;
        }
        $admin = User::query()->where('email', env('ADMIN_EMAIL', 'admin@offshore.local'))->first();
        if (! $admin) {
            throw new RuntimeException('The admin user was not found. Run the main seeders first (php artisan db:seed).');
        }
        $this->a = $admin;

        try {
            $summary = DB::transaction(fn () => $this->load());
        } catch (\Throwable $e) {
            throw new RuntimeException('Offshore charter demo failed, nothing was stored: '.$e->getMessage(), 0, $e);
        }

        $this->command?->info('Offshore charter demo loaded.');
        $this->command?->table(['Item', 'Value'], collect($summary)->map(fn ($v, $k) => [$k, $v])->values()->all());
        $this->command?->line('Checker user: checker@offshore.local / Checker@12345');
    }

    /** @return array<string, string> */
    private function load(): array
    {
        $this->b = User::query()->firstOrCreate(
            ['email' => 'checker@offshore.local'],
            ['name' => 'Checker', 'first_name' => 'Checker', 'last_name' => null, 'password' => 'Checker@12345', 'timezone' => 'Asia/Dubai',
                'status' => UserStatus::Active, 'password_changed_at' => now()],
        );
        $this->b->assignRole(UserRole::SuperAdmin->value);

        // ---- 1. Master data
        $mgo = (int) FuelType::query()->where('code', 'MGO')->value('id');
        $company = function (string $code, string $name, string $role, array $extra = []): Company {
            $c = Company::query()->create(['code' => $code, 'legal_name' => $name, 'normalized_name' => NameNormalizer::normalize($name), 'country' => 'AE',
                'default_currency' => 'USD', 'status' => 'active', 'created_by' => $this->a->id, 'updated_by' => $this->a->id, ...$extra]);
            $c->roles()->create(['role' => $role]);

            return $c;
        };
        $client = $company('DRE', 'Desert Rock Energy LLC', 'charterer', ['payment_terms_days' => 30]);
        $owner = $company('NSL', 'Northern Shipping LLC', 'owner');
        $supplier = $company('GFS', 'Gulf Fuel Supply FZE', 'supplier');

        $base = Port::query()->where('unlocode', 'AEJEA')->first()
            ?? Port::query()->create(['name' => 'Jebel Ali', 'normalized_name' => 'jebel ali', 'unlocode' => 'AEJEA', 'country' => 'AE', 'timezone' => 'Asia/Dubai', 'created_by' => $this->a->id]);
        $field = OffshoreLocation::query()->create(['code' => 'HALA-1', 'name' => 'Hala Field', 'field_name' => 'Hala', 'latitude' => '24.9', 'longitude' => '54.7',
            'timezone' => 'Asia/Dubai', 'nearest_port_id' => $base->id]);
        PortDistance::query()->create(['from_type' => 'port', 'from_id' => $base->id, 'to_type' => 'location', 'to_id' => $field->id, 'route_key' => '', 'distance_nm' => '100',
            'eca_distance_nm' => '0', 'provider' => 'manual', 'notes' => 'Dummy walkthrough distance', 'calculated_at' => now(), 'created_by' => $this->a->id]);

        $vessel = Vessel::query()->create(['code' => 'PSV02', 'name' => 'MV Northern Star', 'vessel_type_id' => VesselType::query()->where('code', 'PSV')->value('id'),
            'owner_company_id' => $owner->id, 'service_speed_kn' => '12', 'created_by' => $this->a->id]);
        $profile = $vessel->consumptionProfiles()->create(['name' => 'Design', 'source' => 'design', 'effective_from' => '2026-01-01', 'is_default' => true]);
        foreach ([['sea_laden', '12', '14'], ['sea_ballast', '12', '13'], ['port_working', '0', '3'], ['dp_operation', '0', '7'], ['standby', '0', '2']] as [$mode, $speed, $mt]) {
            $profile->rates()->create(['mode' => $mode, 'speed_kn' => $speed, 'fuel_type_id' => $mgo, 'consumption_mt_per_day' => $mt]);
        }

        // ---- 2. Enquiry
        $this->as($this->a);
        $enq = $this->api('POST', '/api/v1/enquiries', [
            'business_type' => 'offshore_charter', 'source' => 'direct', 'charterer_company_id' => $client->id, 'cargo_description' => 'Platform supply and ROV support, Hala Field',
            'period_days' => '8', 'rate_idea' => '12000', 'rate_basis' => 'per_day', 'currency' => 'USD', 'laycan_from' => '2026-09-27', 'laycan_to' => '2026-09-28',
            'commission_terms' => '2.5% address + 1.25% brokerage',
            'ports' => [['port_id' => $base->id, 'purpose' => 'load'], ['offshore_location_id' => $field->id, 'purpose' => 'offshore_ops'], ['port_id' => $base->id, 'purpose' => 'redelivery']],
        ], 201);

        // ---- 3. Estimation
        $res = $this->api('POST', '/api/v1/estimations', ['enquiry_id' => $enq['id'], 'vessel_id' => $vessel->id], 201);
        $est = $res['id'];
        $this->expect('offshore_day_rate', $res['estimation_type'], 'estimation type');
        $sc = $res['scenarios'][0]['id'];
        $s = $this->api('GET', "/api/v1/estimations/{$est}/scenarios/{$sc}");
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
        foreach ([['STANDBY', 'Standby day rate', 'per_day', '1', '7000'], ['MOBFEE', 'Mobilization fee', 'lump_sum', null, '18000'], ['DEMOBFEE', 'Demobilization fee', 'lump_sum', null, '15000']] as [$code, $d, $basis, $qty, $rate]) {
            $in['revenue_items'][] = ['revenue_category_id' => RevenueCategory::query()->where('code', $code)->value('id'), 'description' => $d, 'basis' => $basis, 'quantity' => $qty,
                'rate' => $rate, 'currency' => 'USD', 'fx_rate' => '1', 'commissionable' => false, 'address_pct' => '0', 'brokerage_pct' => '0', 'other_pct' => '0', 'primary' => false];
        }
        $in['cost_items'][] = ['expense_category_id' => ExpenseCategory::query()->where('code', 'OPEX')->value('id'), 'description' => 'Operational expense', 'basis' => 'per_day',
            'quantity' => '10', 'rate' => '2200', 'currency' => 'USD', 'fx_rate' => '1', 'account' => 'owner'];
        $saved = $this->api('PUT', "/api/v1/estimations/{$est}/scenarios/{$sc}", ['lock_version' => $s['lock_version'], 'inputs' => $in]);
        $this->expect('calculated', $saved['calc_status'], 'estimation status');
        $this->expect('74231.25', $saved['result']['total_costs'], 'estimated total costs');
        $this->expect('35068.75', $saved['result']['profit'], 'estimated profit');
        $this->api('POST', "/api/v1/estimations/{$est}/scenarios/{$sc}/select");
        $this->api('POST', "/api/v1/estimations/{$est}/submit");
        $this->as($this->b)->api('POST', "/api/v1/estimations/{$est}/approve");

        // ---- 4. Offer, fixture, contract
        $this->as($this->a);
        $offer = $this->api('POST', '/api/v1/offers', ['enquiry_id' => $enq['id'], 'vessel_id' => $vessel->id, 'estimation_scenario_id' => $sc], 201);
        $rev = $offer['revisions'][0]['id'];
        $this->api('PUT', "/api/v1/offers/{$offer['id']}/revisions/{$rev}", ['valid_until' => '2026-09-25', 'terms' => 'Day rate charter. Fuel for owner account. Mob/demob lump sums apply.']);
        $this->api('POST', "/api/v1/offers/{$offer['id']}/revisions/{$rev}/send");
        $this->api('POST', "/api/v1/offers/{$offer['id']}/revisions/{$rev}/accept", ['note' => 'Accepted by email']);
        $fx = $this->api('POST', "/api/v1/offers/{$offer['id']}/revisions/{$rev}/convert-to-fixture", [], 201);
        $this->api('POST', "/api/v1/fixtures/{$fx['id']}/submit");
        $this->as($this->b)->api('POST', "/api/v1/fixtures/{$fx['id']}/approve");
        $this->as($this->a);
        $contract = $this->api('POST', "/api/v1/fixtures/{$fx['id']}/convert-to-contract", [], 201);
        $cid = $contract['id'];
        $contract = $this->api('PUT', "/api/v1/contracts/{$cid}", ['lock_version' => $contract['lock_version'], 'title' => 'Hala Field supply and ROV support 2026',
            'end_date' => '2026-12-31', 'payment_terms_days' => 30]);
        $current = collect($contract['rates'])->map(fn ($r) => array_intersect_key($r, array_flip(['rate_type', 'amount', 'currency', 'unit', 'offshore_activity_type_id', 'description', 'effective_from', 'effective_to'])))->all();
        $rate = fn (string $type, string $amount, string $unit, ?string $activity, string $desc) => ['rate_type' => $type, 'amount' => $amount, 'currency' => 'USD', 'unit' => $unit,
            'offshore_activity_type_id' => $activity ? OffshoreActivityType::query()->where('code', $activity)->value('id') : null, 'description' => $desc];
        $this->api('PUT', "/api/v1/contracts/{$cid}/rates", ['lock_version' => $contract['lock_version'], 'rates' => [
            ...$current,
            $rate('standby_day_rate', '7000', 'per_day', null, 'Standby (weather and waiting)'),
            $rate('mobilization_fee', '18000', 'lump_sum', null, 'Mobilization'),
            $rate('demobilization_fee', '15000', 'lump_sum', null, 'Demobilization'),
            $rate('hourly', '500', 'per_hour', 'ROV', 'ROV support hourly'),
        ]]);
        $this->api('POST', "/api/v1/contracts/{$cid}/submit");
        $this->as($this->b)->api('POST', "/api/v1/contracts/{$cid}/approve");
        $this->as($this->a)->api('POST', "/api/v1/contracts/{$cid}/activate");

        // ---- 5. Voyage, offshore project, port calls
        $voyage = $this->api('POST', "/api/v1/fixtures/{$fx['id']}/convert-to-voyage", [], 201);
        $v = $voyage['id'];
        $this->expect('offshore', $voyage['operation_type'], 'voyage operation type');
        $this->expect((string) $cid, (string) $voyage['contract_id'], 'voyage contract link');
        $project = $this->api('POST', '/api/v1/offshore-projects', ['code' => 'HALA-2026', 'name' => 'Hala Field supply and ROV 2026', 'contract_id' => $cid,
            'offshore_location_id' => $field->id, 'field_name' => 'Hala', 'start_date' => '2026-09-27', 'end_date' => '2026-12-31'], 201);
        $move = fn (string $to, string $at) => $this->api('POST', "/api/v1/voyages/{$v}/transition", ['status' => $to, 'at' => $at]);
        $move('nominated', '2026-09-27T16:00');
        $c1 = $this->api('POST', "/api/v1/voyages/{$v}/port-calls", ['port_id' => $base->id, 'purpose' => 'supply', 'eta' => '2026-09-28T04:00', 'ata' => '2026-09-28T05:00', 'atb' => '2026-09-28T06:00'], 201);
        $c2 = $this->api('POST', "/api/v1/voyages/{$v}/port-calls", ['offshore_location_id' => $field->id, 'purpose' => 'offshore_ops', 'eta' => '2026-09-29T14:00'], 201);
        $c3 = $this->api('POST', "/api/v1/voyages/{$v}/port-calls", ['port_id' => $base->id, 'purpose' => 'other', 'eta' => '2026-10-07T11:00'], 201);
        $move('mobilizing', '2026-09-28T06:00');

        // ---- 6. Bunkers
        $stem = $this->api('POST', '/api/v1/bunker-stems', ['vessel_id' => $vessel->id, 'voyage_id' => $v, 'port_call_id' => $c1['id'], 'fuel_type_id' => $mgo,
            'supplier_company_id' => $supplier->id, 'ordered_on' => '2026-09-27', 'ordered_mt' => '60', 'price_per_mt' => '750', 'currency' => 'USD'], 201);
        $stem = $this->api('POST', "/api/v1/bunker-stems/{$stem['id']}/deliver", ['lock_version' => $stem['lock_version'], 'delivered_at' => '2026-09-28T04:30',
            'delivered_mt' => '60', 'bdn_number' => 'BDN-0301']);
        $this->expect('45000.00', $stem['total_amount'], 'bunker stem total');
        $c1 = $this->api('PUT', "/api/v1/voyages/{$v}/port-calls/{$c1['id']}", ['lock_version' => $c1['lock_version'], 'atd' => '2026-09-29T06:00']);

        // ---- 7. Activities (A creates and submits, B verifies; verifying creates the revenue line)
        $activity = function (string $code, string $from, string $to, string $desc, array $hours) use ($vessel, $v, $project, $field): array {
            $act = $this->api('POST', '/api/v1/offshore-activities', ['vessel_id' => $vessel->id, 'voyage_id' => $v, 'offshore_project_id' => $project['id'],
                'offshore_location_id' => $field->id, 'offshore_activity_type_id' => OffshoreActivityType::query()->where('code', $code)->value('id'),
                'description' => $desc, 'start_at' => $from, 'end_at' => $to, ...$hours], 201);
            $this->api('POST', "/api/v1/offshore-activities/{$act['id']}/submit");
            $verified = $this->as($this->b)->api('POST', "/api/v1/offshore-activities/{$act['id']}/verify", ['comment' => 'OK']);
            $this->as($this->a);

            return $verified;
        };
        $acts = [];
        $acts['MOB'] = $activity('MOB', '2026-09-28T06:00', '2026-09-28T16:00', 'Mobilization at Jebel Ali', ['billable_hours' => '0', 'non_billable_hours' => '10', 'standby_hours' => '0']);
        $move('offshore_operation', '2026-09-29T14:00');
        $c2 = $this->api('PUT', "/api/v1/voyages/{$v}/port-calls/{$c2['id']}", ['lock_version' => $c2['lock_version'], 'ata' => '2026-09-29T14:00']);
        $acts['S1'] = $activity('SUPPLY', '2026-09-29T06:00', '2026-09-30T06:00', 'Supply run 1', ['billable_hours' => '24']);
        $acts['ROV'] = $activity('ROV', '2026-09-30T06:00', '2026-09-30T14:00', 'ROV inspection, subsea manifold', ['billable_hours' => '8']);
        $acts['S2'] = $activity('SUPPLY', '2026-09-30T14:00', '2026-10-02T14:00', 'Supply run 2', ['billable_hours' => '48']);
        $move('standby', '2026-10-02T14:00');
        $acts['SB'] = $activity('STANDBY', '2026-10-02T14:00', '2026-10-03T14:00', 'Waiting on weather', ['billable_hours' => '0', 'standby_hours' => '24']);
        $move('offshore_operation', '2026-10-03T14:00');
        $acts['S3'] = $activity('SUPPLY', '2026-10-03T14:00', '2026-10-06T14:00', 'Supply run 3', ['billable_hours' => '72']);
        $move('demobilizing', '2026-10-06T14:00');
        $acts['DEMOB'] = $activity('DEMOB', '2026-10-06T14:00', '2026-10-07T02:00', 'Demobilization', ['billable_hours' => '0', 'non_billable_hours' => '12', 'standby_hours' => '0']);
        foreach (['MOB' => '18000.00', 'S1' => '12000.00', 'ROV' => '4000.00', 'S2' => '24000.00', 'SB' => '7000.00', 'S3' => '36000.00', 'DEMOB' => '15000.00'] as $key => $amount) {
            $this->expect($amount, $acts[$key]['revenue_amount'], "activity {$key} revenue");
        }

        // ---- 8. Port DAs (final): A creates and submits, B approves
        $dac = fn (string $code) => DaCostCategory::query()->where('code', $code)->value('id');
        $makeDa = function (array $call, array $items) use ($v, $base): void {
            $da = $this->api('POST', '/api/v1/port-das', ['voyage_id' => $v, 'port_call_id' => $call['id'], 'port_id' => $base->id, 'da_type' => 'final', 'currency' => 'USD'], 201);
            $this->api('POST', "/api/v1/port-das/{$da['id']}/items", ['items' => $items]);
            $this->api('POST', "/api/v1/port-das/{$da['id']}/submit");
            $this->as($this->b)->api('POST', "/api/v1/port-das/{$da['id']}/approve");
            $this->as($this->a);
        };
        $makeDa($c1, [
            ['da_cost_category_id' => $dac('PILOT'), 'description' => 'Pilotage', 'estimated_amount' => '1500', 'actual_amount' => '1600'],
            ['da_cost_category_id' => $dac('BERTH'), 'description' => 'Berth / dockage', 'estimated_amount' => '2500', 'actual_amount' => '2400'],
            ['da_cost_category_id' => $dac('AGENCY'), 'description' => 'Agency fee', 'estimated_amount' => '700', 'actual_amount' => '700'],
        ]);
        $this->api('PUT', "/api/v1/voyages/{$v}/port-calls/{$c2['id']}", ['lock_version' => $c2['lock_version'], 'atd' => '2026-10-07T03:00']);
        $c3 = $this->api('PUT', "/api/v1/voyages/{$v}/port-calls/{$c3['id']}", ['lock_version' => $c3['lock_version'], 'ata' => '2026-10-07T11:00', 'atb' => '2026-10-07T12:00', 'atd' => '2026-10-07T14:00']);
        $makeDa($c3, [
            ['da_cost_category_id' => $dac('PILOT'), 'description' => 'Pilotage', 'estimated_amount' => '1000', 'actual_amount' => '900'],
            ['da_cost_category_id' => $dac('BERTH'), 'description' => 'Berth / dockage', 'estimated_amount' => '1500', 'actual_amount' => '1600'],
            ['da_cost_category_id' => $dac('AGENCY'), 'description' => 'Agency fee', 'estimated_amount' => '500', 'actual_amount' => '500'],
        ]);
        $this->api('POST', "/api/v1/voyages/{$v}/complete", ['at' => '2026-10-07T16:00']);

        // ---- 9. Costs entered by hand: A creates and confirms, B approves
        $expense = fn (string $code, string $desc, array $money) => $this->api('POST', '/api/v1/voyage-expenses', ['voyage_id' => $v, 'contract_id' => $cid,
            'expense_category_id' => ExpenseCategory::query()->where('code', $code)->value('id'), 'description' => $desc, 'currency' => 'USD', ...$money], 201);
        $lines = [
            $expense('BUNKER', 'Bunkers, 60 MT MGO @ 750 (BDN-0301)', ['quantity' => '60', 'rate' => '750', 'supplier_company_id' => $supplier->id, 'incurred_at' => '2026-09-28']),
            $expense('OPEX', 'Operational expense, crew, insurance and maintenance (10 days x 2,200)', ['amount' => '22000', 'incurred_at' => '2026-10-07']),
        ];
        foreach ($lines as $e) {
            $this->api('POST', "/api/v1/voyage-expenses/{$e['id']}/confirm");
            $this->as($this->b)->api('POST', "/api/v1/voyage-expenses/{$e['id']}/approve");
            $this->as($this->a);
        }
        // The Port DA expense lines were created by B's approval, so A approves them.
        foreach ($this->api('GET', "/api/v1/voyage-expenses?voyage_id={$v}&per_page=50") as $e) {
            if ($e['status'] === 'confirmed') {
                $this->api('POST', "/api/v1/voyage-expenses/{$e['id']}/approve");
            }
        }

        // ---- 10. Invoice, payments, supplier payable
        $revIds = collect($this->api('GET', "/api/v1/voyage-revenues?voyage_id={$v}&status=confirmed&per_page=50"))->pluck('id')->all();
        $this->expect('7', (string) count($revIds), 'confirmed revenue lines');
        $inv = $this->api('POST', '/api/v1/invoices', ['invoice_type' => 'offshore_service', 'customer_company_id' => $client->id, 'contract_id' => $cid, 'voyage_id' => $v,
            'issue_date' => '2026-10-08', 'due_date' => '2026-11-07', 'currency' => 'USD', 'revenue_ids' => $revIds], 201);
        $inv = $this->api('POST', "/api/v1/invoices/{$inv['id']}/issue");
        $this->expect('116000.00', $inv['total'], 'invoice total');
        $pay = $this->api('POST', '/api/v1/payments', ['direction' => 'received', 'currency' => 'USD', 'company_id' => $client->id, 'payment_date' => '2026-10-09',
            'amount' => '116000', 'method' => 'wire', 'bank_reference' => 'TT-DRE-01'], 201);
        $this->api('POST', "/api/v1/payments/{$pay['id']}/allocate", ['allocations' => [['invoice_id' => $inv['id'], 'amount' => '116000']]]);

        $payable = $this->api('POST', '/api/v1/payables', ['supplier_company_id' => $supplier->id, 'supplier_invoice_ref' => 'GFS-INV-3301', 'currency' => 'USD',
            'issue_date' => '2026-09-28', 'due_date' => '2026-10-28', 'subtotal' => '45000', 'tax' => '0'], 201);
        $this->as($this->b)->api('POST', "/api/v1/payables/{$payable['id']}/approve");
        $this->as($this->a);
        $out = $this->api('POST', '/api/v1/payments', ['direction' => 'paid', 'currency' => 'USD', 'company_id' => $supplier->id, 'payment_date' => '2026-10-09',
            'amount' => '45000', 'method' => 'wire', 'bank_reference' => 'TT-OUT-3301'], 201);
        $this->api('POST', "/api/v1/payments/{$out['id']}/allocate", ['allocations' => [['payable_id' => $payable['id'], 'amount' => '45000']]]);

        // ---- 11. Finalize and P&L
        foreach ($this->api('GET', "/api/v1/voyages/{$v}/finance-gates") as $gate) {
            if (! $gate['passed']) {
                throw new RuntimeException('A finalization gate did not pass: '.($gate['message'] ?? ''));
            }
        }
        $final = $this->api('POST', "/api/v1/voyages/{$v}/finalize");
        $this->expect('finalized', $final['status'], 'voyage status');
        $pnl = $this->api('GET', "/api/v1/voyages/{$v}/financials");
        $rows = collect($pnl['rows'])->keyBy('metric');
        $this->expect('116000.00', $rows['gross_revenue']['actual'], 'actual revenue');
        $this->expect('74700.00', $rows['total_costs']['actual'], 'actual expenses');
        $this->expect('38450.00', $rows['net_profit']['actual'], 'net profit');
        $this->expect('33.15', $pnl['margin_pct'], 'margin');

        return [
            'Enquiry' => $enq['enquiry_number'],
            'Voyage' => $final['voyage_number'].' ('.$final['status'].')',
            'Project' => 'HALA-2026',
            'Invoice' => $inv['invoice_number'].' · '.$inv['total'].' USD',
            'Estimated net profit' => $rows['net_profit']['estimate'],
            'Actual net profit' => $rows['net_profit']['actual'].' ('.$pnl['margin_pct'].' % margin)',
            'Variance' => $rows['net_profit']['variance'].' ('.$rows['net_profit']['variance_pct'].' %)',
        ];
    }
}
