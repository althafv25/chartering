<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Company;
use App\Models\ExpenseCategory;
use App\Models\FuelType;
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
 * One complete trip time charter, from enquiry to a finalized voyage with its P&L, built with the real workflow.
 *
 *   php artisan db:seed --class=TimeCharterDemoSeeder
 *
 * Data: charterer Delta Commodities FZE, MV Time Voyager, USD 12,000 per day for 6 days, Jebel Ali (delivery) to Ras Tanura
 * (redelivery). The timeline, hire and off-hire are the ones in docs/time-charter-dataflow.md. On top of that this demo books
 * every owner-side revenue and cost a time charter can carry: hire, bunkers taken over by the charterer at delivery, operational
 * cost, owner-bought bunkers, bunkers paid to the charterer at redelivery, crew change, and off-hire repairs.
 * Charterer-paid fuel and port costs are not booked (they are not owner costs). docs/time-charter-demo-data.md has the details.
 *
 * Every step goes through the application's own API (same validation, workflow and maker-checker rules as the screens), as user A
 * (admin@offshore.local) and user B (checker@offshore.local, created if missing).
 *
 * Nothing is stored unless every step and the headline figures succeed (one transaction). Running it again does nothing.
 * It is NOT part of DatabaseSeeder and refuses to run in production. Run it on or after 10 Oct 2026, because the actual
 * times must not be in the future.
 *
 * Open the voyage TCV01-26-… → P&L tab to see: estimated net profit 44,100.00, actual net profit 33,193.75.
 */
class TimeCharterDemoSeeder extends Seeder
{
    use DrivesApi;

    private User $a;

    private User $b;

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('The time charter demo data is for local and demo environments only.');
        }

        if (Vessel::query()->where('code', 'TCV01')->exists() || Company::query()->where('legal_name', 'Delta Commodities FZE')->exists()) {
            $this->command?->warn('The time charter demo already exists (vessel TCV01 or company Delta Commodities FZE). Nothing to do.');

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
            throw new RuntimeException('Time charter demo failed, nothing was stored: '.$e->getMessage(), 0, $e);
        }

        $this->command?->info('Time charter demo loaded.');
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
        $hfo = (int) FuelType::query()->where('code', 'HFO')->value('id');
        $mkCompany = function (string $code, string $name, string $role, array $extra = []): Company {
            $c = Company::query()->create(['code' => $code, 'legal_name' => $name, 'normalized_name' => NameNormalizer::normalize($name), 'country' => 'AE',
                'default_currency' => 'USD', 'status' => 'active', 'created_by' => $this->a->id, 'updated_by' => $this->a->id, ...$extra]);
            $c->roles()->create(['role' => $role]);

            return $c;
        };
        $delta = $mkCompany('DCF', 'Delta Commodities FZE', 'charterer', ['payment_terms_days' => 15]);
        $supplier = Company::query()->where('legal_name', 'Oceanic Fuels Trading LLC')->first()
            ?? $mkCompany('OFT', 'Oceanic Fuels Trading LLC', 'supplier');
        $jebel = Port::query()->where('unlocode', 'AEJEA')->first()
            ?? Port::query()->create(['name' => 'Jebel Ali', 'normalized_name' => 'jebel ali', 'unlocode' => 'AEJEA', 'country' => 'AE', 'timezone' => 'Asia/Dubai', 'created_by' => $this->a->id]);
        $ras = Port::query()->where('unlocode', 'SARTA')->first()
            ?? Port::query()->create(['name' => 'Ras Tanura', 'normalized_name' => 'ras tanura', 'unlocode' => 'SARTA', 'country' => 'SA', 'timezone' => 'Asia/Riyadh', 'created_by' => $this->a->id]);
        PortDistance::query()->updateOrCreate(
            ['from_type' => 'port', 'from_id' => $jebel->id, 'to_type' => 'port', 'to_id' => $ras->id, 'route_key' => ''],
            ['distance_nm' => '330', 'eca_distance_nm' => '0', 'provider' => 'manual', 'notes' => 'Dummy walkthrough distance', 'calculated_at' => now(), 'created_by' => $this->a->id],
        );
        // 13.75 kn x 24 h = 330 NM per day, so the 330 NM sea leg takes exactly 1 day.
        $vessel = Vessel::query()->create(['code' => 'TCV01', 'name' => 'MV Time Voyager',
            'vessel_type_id' => VesselType::query()->where('name', 'Bulk Carrier')->value('id') ?? VesselType::query()->value('id'),
            'service_speed_kn' => '13.75', 'created_by' => $this->a->id]);
        $profile = $vessel->consumptionProfiles()->create(['name' => 'Design', 'source' => 'design', 'effective_from' => '2026-01-01', 'is_default' => true]);
        $profile->rates()->create(['mode' => 'sea_laden', 'speed_kn' => '13.75', 'fuel_type_id' => $hfo, 'consumption_mt_per_day' => '20']);
        $profile->rates()->create(['mode' => 'port_working', 'speed_kn' => '0', 'fuel_type_id' => $hfo, 'consumption_mt_per_day' => '5']);

        $cat = fn (string $code) => ExpenseCategory::query()->where('code', $code)->value('id');
        $rcat = fn (string $code) => RevenueCategory::query()->where('code', $code)->value('id');

        // ---- 2. Enquiry (time charter, hire per day)
        $this->as($this->a);
        $enq = $this->api('POST', '/api/v1/enquiries', [
            'business_type' => 'time_charter', 'source' => 'direct', 'charterer_company_id' => $delta->id, 'cargo_description' => 'Trip time charter, general cargo',
            'period_days' => '6', 'rate_idea' => '12000', 'rate_basis' => 'per_day', 'currency' => 'USD', 'laycan_from' => '2026-10-01', 'laycan_to' => '2026-10-02',
            'commission_terms' => '2.5% address + 1.25% brokerage',
            'ports' => [['port_id' => $jebel->id, 'purpose' => 'delivery'], ['port_id' => $ras->id, 'purpose' => 'redelivery']],
        ], 201);

        // ---- 3. Estimation: the planned hire, operating cost, bunkers on board at delivery and redelivery
        $res = $this->api('POST', '/api/v1/estimations', ['enquiry_id' => $enq['id'], 'vessel_id' => $vessel->id], 201);
        $est = $res['id'];
        $this->expect('time_charter', $res['estimation_type'], 'estimation type');
        $sc = $res['scenarios'][0]['id'];
        $s = $this->api('GET', "/api/v1/estimations/{$est}/scenarios/{$sc}");
        $in = $s['inputs'];
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
        $in['revenue_items'][] = ['revenue_category_id' => $rcat('OTHER'), 'description' => 'Bunkers on board at delivery, 30 MT @ 600 (taken over by charterer)',
            'basis' => 'lump_sum', 'quantity' => null, 'rate' => '18000', 'currency' => 'USD', 'fx_rate' => '1', 'commissionable' => false,
            'address_pct' => '0', 'brokerage_pct' => '0', 'other_pct' => '0', 'primary' => false];
        $in['cost_items'][] = ['expense_category_id' => $cat('OPEX'), 'description' => 'Operational expense', 'basis' => 'per_day',
            'quantity' => null, 'rate' => '3000', 'currency' => 'USD', 'fx_rate' => '1', 'account' => 'owner'];
        $in['cost_items'][] = ['expense_category_id' => $cat('BUNKER'), 'description' => 'Bunkers bought by owner before delivery, 30 MT @ 600',
            'basis' => 'lump_sum', 'quantity' => null, 'rate' => '18000', 'currency' => 'USD', 'fx_rate' => '1', 'account' => 'owner'];
        $in['cost_items'][] = ['expense_category_id' => $cat('OTHER'), 'description' => 'Bunkers on board at redelivery, 12 MT @ 600 (paid to charterer)',
            'basis' => 'lump_sum', 'quantity' => null, 'rate' => '7200', 'currency' => 'USD', 'fx_rate' => '1', 'account' => 'owner'];
        $saved = $this->api('PUT', "/api/v1/estimations/{$est}/scenarios/{$sc}", ['lock_version' => $s['lock_version'], 'inputs' => $in]);
        $this->expect('calculated', $saved['calc_status'], 'estimation status');
        $this->expect('90000.00', $saved['result']['gross_revenue'], 'estimated gross revenue');
        $this->expect('43200.00', $saved['result']['total_costs'], 'estimated total costs');
        $this->expect('44100.00', $saved['result']['profit'], 'estimated profit');
        $this->api('POST', "/api/v1/estimations/{$est}/scenarios/{$sc}/select");
        $this->api('POST', "/api/v1/estimations/{$est}/submit");
        $this->as($this->b)->api('POST', "/api/v1/estimations/{$est}/approve");

        // ---- 4. Offer, fixture, contract
        $this->as($this->a);
        $offer = $this->api('POST', '/api/v1/offers', ['enquiry_id' => $enq['id'], 'vessel_id' => $vessel->id, 'estimation_scenario_id' => $sc], 201);
        $rev = $offer['revisions'][0]['id'];
        $this->api('PUT', "/api/v1/offers/{$offer['id']}/revisions/{$rev}", ['valid_until' => '2026-09-30',
            'terms' => 'Trip time charter. Charterer pays bunkers and port costs. Hire payable within 15 days of invoice.']);
        $this->api('POST', "/api/v1/offers/{$offer['id']}/revisions/{$rev}/send");
        $this->api('POST', "/api/v1/offers/{$offer['id']}/revisions/{$rev}/accept", ['note' => 'Accepted by email']);
        $fx = $this->api('POST', "/api/v1/offers/{$offer['id']}/revisions/{$rev}/convert-to-fixture", [], 201);
        $this->api('POST', "/api/v1/fixtures/{$fx['id']}/submit");
        $this->as($this->b)->api('POST', "/api/v1/fixtures/{$fx['id']}/approve");

        $this->as($this->a);
        $contract = $this->api('POST', "/api/v1/fixtures/{$fx['id']}/convert-to-contract", [], 201);
        $cid = $contract['id'];
        $this->expect('time_charter', $contract['contract_type'], 'contract type');
        $this->api('PUT', "/api/v1/contracts/{$cid}", ['lock_version' => $contract['lock_version'], 'title' => 'Delta Commodities, MV Time Voyager trip time charter',
            'start_date' => '2026-10-01', 'end_date' => '2026-12-31', 'payment_terms_days' => 15, 'payment_terms_text' => 'Hire payable within 15 days of invoice']);
        $this->api('POST', "/api/v1/contracts/{$cid}/submit");
        $this->as($this->b)->api('POST', "/api/v1/contracts/{$cid}/approve");
        $this->as($this->a)->api('POST', "/api/v1/contracts/{$cid}/activate");

        // ---- 5. Voyage, port calls and status ladder (delivery = Loading, redelivery = Complete)
        $voyage = $this->api('POST', "/api/v1/fixtures/{$fx['id']}/convert-to-voyage", [], 201);
        $v = $voyage['id'];
        $this->expect('time_charter', $voyage['operation_type'], 'voyage operation type');
        $this->expect((string) $cid, (string) $voyage['contract_id'], 'voyage contract link');
        $move = fn (string $to, string $at) => $this->api('POST', "/api/v1/voyages/{$v}/transition", ['status' => $to, 'at' => $at]);
        $move('nominated', '2026-09-30T10:00');
        $move('loading', '2026-10-01T06:00');
        $ja = $this->api('POST', "/api/v1/voyages/{$v}/port-calls", ['port_id' => $jebel->id, 'purpose' => 'load', 'eta' => '2026-10-01T04:00',
            'ata' => '2026-10-01T05:00', 'atb' => '2026-10-01T06:00'], 201);
        $rt = $this->api('POST', "/api/v1/voyages/{$v}/port-calls", ['port_id' => $ras->id, 'purpose' => 'discharge', 'eta' => '2026-10-04T07:00'], 201);

        // ---- 6. Bunkers on board at delivery: the owner bought 30 MT at Jebel Ali and the charterer takes them over
        $stem = $this->api('POST', '/api/v1/bunker-stems', ['vessel_id' => $vessel->id, 'voyage_id' => $v, 'port_call_id' => $ja['id'], 'fuel_type_id' => $hfo,
            'supplier_company_id' => $supplier->id, 'ordered_on' => '2026-09-30', 'ordered_mt' => '30', 'price_per_mt' => '600', 'currency' => 'USD'], 201);
        $stem = $this->api('POST', "/api/v1/bunker-stems/{$stem['id']}/deliver", ['lock_version' => $stem['lock_version'], 'delivered_at' => '2026-10-01T05:30',
            'delivered_mt' => '30', 'bdn_number' => 'BDN-TCV-0001']);
        $this->expect('18000.00', $stem['total_amount'], 'bunker stem total');

        $ja = $this->api('PUT', "/api/v1/voyages/{$v}/port-calls/{$ja['id']}", ['lock_version' => $ja['lock_version'], 'atd' => '2026-10-03T08:00']);
        $move('loaded', '2026-10-03T07:00');
        $move('sailing', '2026-10-03T08:00');
        $rt = $this->api('PUT', "/api/v1/voyages/{$v}/port-calls/{$rt['id']}", ['lock_version' => $rt['lock_version'], 'ata' => '2026-10-04T07:00', 'atb' => '2026-10-04T08:00']);
        $move('discharging', '2026-10-04T09:00');
        $this->api('PUT', "/api/v1/voyages/{$v}/port-calls/{$rt['id']}", ['lock_version' => $rt['lock_version'], 'atd' => '2026-10-07T08:00']);

        // Off-hire: 12 hours of main engine breakdown. The event must be agreed before the voyage can be finalized.
        $off = $this->api('POST', "/api/v1/voyages/{$v}/off-hire", ['from_at' => '2026-10-05T02:00', 'to_at' => '2026-10-05T14:00', 'reason_code' => 'breakdown',
            'description' => 'Main engine fault, 12 hours'], 201);
        $this->expect('12.0000', $off['hours'], 'off-hire hours');
        $this->api('POST', "/api/v1/voyages/{$v}/complete", ['at' => '2026-10-07T09:00']);
        $this->as($this->b)->api('POST', "/api/v1/voyages/{$v}/off-hire/{$off['id']}/agree", ['comment' => 'Agreed with charterer']);
        $this->as($this->a);

        // ---- 7. Finance: every revenue and cost line is entered by hand, then confirmed (A) and approved (B)
        $hire = $this->api('POST', '/api/v1/voyage-revenues', ['voyage_id' => $v, 'contract_id' => $cid, 'revenue_category_id' => $rcat('HIRE'),
            'description' => 'Hire, 1 Oct 06:00 to 7 Oct 09:00 (6.125 days less 0.5 day off-hire)', 'quantity' => '5.625', 'rate' => '12000',
            'currency' => 'USD', 'commission_pct_total' => '3.75'], 201);
        $this->expect('67500.00', $hire['amount'], 'hire amount');
        $bunkersIn = $this->api('POST', '/api/v1/voyage-revenues', ['voyage_id' => $v, 'contract_id' => $cid, 'revenue_category_id' => $rcat('OTHER'),
            'description' => 'Bunkers on board at delivery, 30 MT @ 600 (taken over by charterer)', 'quantity' => '30', 'rate' => '600', 'currency' => 'USD'], 201);
        $this->expect('18000.00', $bunkersIn['amount'], 'delivery bunkers amount');
        foreach ([$hire, $bunkersIn] as $line) {
            $this->api('POST', "/api/v1/voyage-revenues/{$line['id']}/confirm");
        }

        $expenses = [
            $this->api('POST', '/api/v1/voyage-expenses', ['voyage_id' => $v, 'contract_id' => $cid, 'expense_category_id' => $cat('OPEX'),
                'description' => 'Operational expense, crew, insurance and maintenance (6.125 days x 3,000)', 'quantity' => '6.125', 'rate' => '3000',
                'currency' => 'USD', 'incurred_at' => '2026-10-07'], 201),
            $this->api('POST', '/api/v1/voyage-expenses', ['voyage_id' => $v, 'contract_id' => $cid, 'expense_category_id' => $cat('BUNKER'),
                'description' => 'Bunkers bought by owner before delivery, 30 MT @ 600 (BDN-TCV-0001)', 'quantity' => '30', 'rate' => '600', 'currency' => 'USD',
                'supplier_company_id' => $supplier->id, 'incurred_at' => '2026-10-01'], 201),
            $this->api('POST', '/api/v1/voyage-expenses', ['voyage_id' => $v, 'contract_id' => $cid, 'expense_category_id' => $cat('OTHER'),
                'description' => 'Bunkers on board at redelivery, 12 MT @ 600 (paid to charterer)', 'quantity' => '12', 'rate' => '600', 'currency' => 'USD',
                'incurred_at' => '2026-10-07'], 201),
            $this->api('POST', '/api/v1/voyage-expenses', ['voyage_id' => $v, 'contract_id' => $cid, 'expense_category_id' => $cat('OTHER'),
                'description' => 'Crew change at Ras Tanura (owner cost)', 'amount' => '2000', 'currency' => 'USD', 'incurred_at' => '2026-10-07'], 201),
            $this->api('POST', '/api/v1/voyage-expenses', ['voyage_id' => $v, 'contract_id' => $cid, 'expense_category_id' => $cat('OTHER'),
                'description' => 'Main engine repair during off-hire (owner cost)', 'amount' => '4200', 'currency' => 'USD', 'incurred_at' => '2026-10-05'], 201),
        ];
        foreach ($expenses as $e) {
            $this->api('POST', "/api/v1/voyage-expenses/{$e['id']}/confirm");
            $this->as($this->b)->api('POST', "/api/v1/voyage-expenses/{$e['id']}/approve");
            $this->as($this->a);
        }

        // ---- 8. Invoices: hire and delivery bunkers are billed separately, then paid in one wire
        $inv = fn (string $type, string $due, array $revenueIds) => $this->api('POST', '/api/v1/invoices', ['invoice_type' => $type,
            'customer_company_id' => $delta->id, 'contract_id' => $cid, 'voyage_id' => $v, 'issue_date' => '2026-10-08', 'due_date' => $due,
            'currency' => 'USD', 'revenue_ids' => $revenueIds], 201);
        $draftHire = $inv('hire', '2026-10-23', [$hire['id']]);
        $draftBunkers = $inv('other', '2026-10-23', [$bunkersIn['id']]);
        $invHire = $this->api('POST', "/api/v1/invoices/{$draftHire['id']}/issue");
        $invBunkers = $this->api('POST', "/api/v1/invoices/{$draftBunkers['id']}/issue");
        $this->expect('67500.00', $invHire['total'], 'hire invoice total');
        $this->expect('18000.00', $invBunkers['total'], 'bunker invoice total');

        $pay = $this->api('POST', '/api/v1/payments', ['direction' => 'received', 'currency' => 'USD', 'company_id' => $delta->id, 'payment_date' => '2026-10-09',
            'amount' => '85500', 'method' => 'wire', 'bank_reference' => 'TT-DELTA-02'], 201);
        $this->api('POST', "/api/v1/payments/{$pay['id']}/allocate", ['allocations' => [
            ['invoice_id' => $invHire['id'], 'amount' => '67500'], ['invoice_id' => $invBunkers['id'], 'amount' => '18000'],
        ]]);

        // Supplier payable for the bunkers the owner bought before delivery.
        $payable = $this->api('POST', '/api/v1/payables', ['supplier_company_id' => $supplier->id, 'supplier_invoice_ref' => 'OF-INV-7790', 'currency' => 'USD',
            'issue_date' => '2026-10-01', 'due_date' => '2026-10-30', 'subtotal' => '18000', 'tax' => '0'], 201);
        $this->as($this->b)->api('POST', "/api/v1/payables/{$payable['id']}/approve");
        $this->as($this->a);
        $out = $this->api('POST', '/api/v1/payments', ['direction' => 'paid', 'currency' => 'USD', 'company_id' => $supplier->id, 'payment_date' => '2026-10-09',
            'amount' => '18000', 'method' => 'wire', 'bank_reference' => 'TT-OUT-7790'], 201);
        $this->api('POST', "/api/v1/payments/{$out['id']}/allocate", ['allocations' => [['payable_id' => $payable['id'], 'amount' => '18000']]]);

        // ---- 9. Finalize and P&L
        foreach ($this->api('GET', "/api/v1/voyages/{$v}/finance-gates") as $gate) {
            if (! $gate['passed']) {
                throw new RuntimeException('A finalization gate did not pass: '.($gate['message'] ?? ''));
            }
        }
        $final = $this->api('POST', "/api/v1/voyages/{$v}/finalize");
        $this->expect('finalized', $final['status'], 'voyage status');

        $pnl = $this->api('GET', "/api/v1/voyages/{$v}/financials");
        $rows = collect($pnl['rows'])->keyBy('metric');
        $this->expect('85500.00', $rows['gross_revenue']['actual'], 'actual revenue');
        $this->expect('49775.00', $rows['total_costs']['actual'], 'actual expenses');
        $this->expect('33193.75', $rows['net_profit']['actual'], 'net profit');

        return [
            'Enquiry' => $enq['enquiry_number'],
            'Voyage' => $final['voyage_number'].' ('.$final['status'].')',
            'Invoices' => $invHire['invoice_number'].' · '.$invHire['total'].' USD, '.$invBunkers['invoice_number'].' · '.$invBunkers['total'].' USD',
            'Estimated net profit' => $rows['net_profit']['estimate'],
            'Actual net profit' => $rows['net_profit']['actual'].' ('.$pnl['margin_pct'].' % margin)',
            'Variance' => $rows['net_profit']['variance'].' ('.$rows['net_profit']['variance_pct'].' %)',
        ];
    }
}
