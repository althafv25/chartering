<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Company;
use App\Models\DaCostCategory;
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
 * One complete voyage charter, from enquiry to a finalized voyage with its P&L, built with the real workflow.
 *
 *   php artisan db:seed --class=VoyageCharterDemoSeeder
 *
 * Data: charterer Gulf Trading FZE, MV Example Trader, 2,000 MT general cargo Jebel Ali → Ras Tanura at USD 55 per MT, with a
 * laytime discharge (demurrage). It is the job described in docs/dataflow.md. Every step goes through the application's own API
 * (same validation, workflow and maker-checker rules as the screens), as user A (admin@offshore.local) and user B
 * (checker@offshore.local, created if missing).
 *
 * Nothing is stored unless every step and the headline figures succeed (one transaction). Running it again does nothing.
 * It is NOT part of DatabaseSeeder and refuses to run in production. Run it on or after 8 Oct 2026 (the voyage dates are in
 * September and October 2026 and must not be in the future).
 *
 * Open the voyage EXT01-26-… → P&L tab to see: estimated profit 71,050.00, actual net profit 71,800.00 (62.98 %).
 */
class VoyageCharterDemoSeeder extends Seeder
{
    use DrivesApi;

    private User $a;

    private User $b;

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('The voyage charter demo data is for local and demo environments only.');
        }
        if (Vessel::query()->where('code', 'EXT01')->exists() || Company::query()->where('legal_name', 'Gulf Trading FZE')->exists()) {
            $this->command?->warn('The voyage charter demo already exists (vessel EXT01 or company Gulf Trading FZE). Nothing to do.');

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
            throw new RuntimeException('Voyage charter demo failed, nothing was stored: '.$e->getMessage(), 0, $e);
        }

        $this->command?->info('Voyage charter demo loaded.');
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
        $gulf = $mkCompany('GTF', 'Gulf Trading FZE', 'charterer', ['payment_terms_days' => 30]);
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

        $vessel = Vessel::query()->create(['code' => 'EXT01', 'name' => 'MV Example Trader',
            'vessel_type_id' => VesselType::query()->where('name', 'Bulk Carrier')->value('id') ?? VesselType::query()->value('id'),
            'service_speed_kn' => '12', 'created_by' => $this->a->id]);
        $profile = $vessel->consumptionProfiles()->create(['name' => 'Design', 'source' => 'design', 'effective_from' => '2026-01-01', 'is_default' => true]);
        $profile->rates()->create(['mode' => 'sea_laden', 'speed_kn' => '12', 'fuel_type_id' => $hfo, 'consumption_mt_per_day' => '20']);
        $profile->rates()->create(['mode' => 'port_working', 'speed_kn' => '0', 'fuel_type_id' => $hfo, 'consumption_mt_per_day' => '5']);

        // ---- 2. Enquiry (voyage charter, freight per MT)
        $this->as($this->a);
        $enq = $this->api('POST', '/api/v1/enquiries', [
            'business_type' => 'voyage_charter', 'source' => 'direct', 'charterer_company_id' => $gulf->id, 'cargo_description' => 'General cargo',
            'quantity' => '2000', 'quantity_unit' => 'mt', 'laycan_from' => '2026-10-01', 'laycan_to' => '2026-10-05', 'rate_idea' => '55', 'rate_basis' => 'per_mt',
            'currency' => 'USD', 'commission_terms' => '2.5% address + 2.5% brokerage',
            'ports' => [['port_id' => $jebel->id, 'purpose' => 'load'], ['port_id' => $ras->id, 'purpose' => 'discharge']],
        ], 201);

        // ---- 3. Estimation
        $res = $this->api('POST', '/api/v1/estimations', ['enquiry_id' => $enq['id'], 'vessel_id' => $vessel->id], 201);
        $est = $res['id'];
        $this->expect('voyage_charter', $res['estimation_type'], 'estimation type');
        $sc = $res['scenarios'][0]['id'];
        $s = $this->api('GET', "/api/v1/estimations/{$est}/scenarios/{$sc}");
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
        $saved = $this->api('PUT', "/api/v1/estimations/{$est}/scenarios/{$sc}", ['lock_version' => $s['lock_version'], 'inputs' => $in]);
        $this->expect('calculated', $saved['calc_status'], 'estimation status');
        $this->expect('33450.00', $saved['result']['voyage_costs'], 'estimated voyage costs');
        $this->expect('71050.00', $saved['result']['profit'], 'estimated profit');
        $this->api('POST', "/api/v1/estimations/{$est}/scenarios/{$sc}/select");
        $this->api('POST', "/api/v1/estimations/{$est}/submit");
        $this->as($this->b)->api('POST', "/api/v1/estimations/{$est}/approve");

        // ---- 4. Offer, fixture, contract
        $this->as($this->a);
        $offer = $this->api('POST', '/api/v1/offers', ['enquiry_id' => $enq['id'], 'vessel_id' => $vessel->id, 'estimation_scenario_id' => $sc], 201);
        $rev = $offer['revisions'][0]['id'];
        $this->api('PUT', "/api/v1/offers/{$offer['id']}/revisions/{$rev}", ['valid_until' => '2026-10-03', 'terms' => 'Subject to charter party. Freight payable within 30 days of discharge.']);
        $this->api('POST', "/api/v1/offers/{$offer['id']}/revisions/{$rev}/send");
        $this->api('POST', "/api/v1/offers/{$offer['id']}/revisions/{$rev}/accept", ['note' => 'Accepted by email']);
        $fx = $this->api('POST', "/api/v1/offers/{$offer['id']}/revisions/{$rev}/convert-to-fixture", [], 201);
        $this->api('POST', "/api/v1/fixtures/{$fx['id']}/submit");
        $this->as($this->b)->api('POST', "/api/v1/fixtures/{$fx['id']}/approve");
        $this->as($this->a);
        $contract = $this->api('POST', "/api/v1/fixtures/{$fx['id']}/convert-to-contract", [], 201);
        $cid = $contract['id'];
        $this->api('PUT', "/api/v1/contracts/{$cid}", ['lock_version' => $contract['lock_version'], 'title' => 'Gulf Trading — MV Example Trader',
            'start_date' => '2026-10-01', 'end_date' => '2026-12-31', 'payment_terms_days' => 30, 'payment_terms_text' => 'Freight payable within 30 days of discharge']);
        $this->api('POST', "/api/v1/contracts/{$cid}/submit");
        $this->as($this->b)->api('POST', "/api/v1/contracts/{$cid}/approve");
        $this->as($this->a)->api('POST', "/api/v1/contracts/{$cid}/activate");

        // ---- 5. Voyage and port calls
        $voyage = $this->api('POST', "/api/v1/fixtures/{$fx['id']}/convert-to-voyage", [], 201);
        $v = $voyage['id'];
        $this->expect((string) $cid, (string) $voyage['contract_id'], 'voyage contract link');
        $move = fn (string $to, string $at) => $this->api('POST', "/api/v1/voyages/{$v}/transition", ['status' => $to, 'at' => $at]);
        $move('nominated', '2026-09-30T10:00');
        $move('loading', '2026-10-01T09:00');
        $ja = $this->api('POST', "/api/v1/voyages/{$v}/port-calls", ['port_id' => $jebel->id, 'purpose' => 'load', 'eta' => '2026-10-01T06:00',
            'ata' => '2026-10-01T06:00', 'atb' => '2026-10-01T09:00'], 201);
        $rt = $this->api('POST', "/api/v1/voyages/{$v}/port-calls", ['port_id' => $ras->id, 'purpose' => 'discharge', 'eta' => '2026-10-04T12:00'], 201);

        // ---- 6. Bunkers at Jebel Ali
        $stem = $this->api('POST', '/api/v1/bunker-stems', ['vessel_id' => $vessel->id, 'voyage_id' => $v, 'port_call_id' => $ja['id'], 'fuel_type_id' => $hfo,
            'supplier_company_id' => $supplier->id, 'ordered_on' => '2026-09-30', 'ordered_mt' => '44', 'price_per_mt' => '600', 'currency' => 'USD'], 201);
        $stem = $this->api('POST', "/api/v1/bunker-stems/{$stem['id']}/deliver", ['lock_version' => $stem['lock_version'], 'delivered_at' => '2026-10-02T10:00',
            'delivered_mt' => '44', 'bdn_number' => 'BDN-0001']);
        $this->expect('26400.00', $stem['total_amount'], 'bunker stem total');
        $ja = $this->api('PUT', "/api/v1/voyages/{$v}/port-calls/{$ja['id']}", ['lock_version' => $ja['lock_version'], 'atd' => '2026-10-03T10:00']);
        $move('loaded', '2026-10-03T09:00');
        $move('sailing', '2026-10-03T10:00');

        // ---- 7. Arrival report at Ras Tanura (applies the ATA); A submits, B verifies
        $report = $this->api('POST', '/api/v1/captain-reports', ['vessel_id' => $vessel->id, 'voyage_id' => $v, 'port_call_id' => $rt['id'], 'report_type' => 'arrival',
            'reported_at' => '2026-10-04T12:30:00+03:00', 'distance_since_last_nm' => '330',
            'fuel_lines' => [['fuel_type_id' => $hfo, 'rob_mt' => '77', 'consumed_mt' => '23', 'received_mt' => '0']]], 201);
        $this->api('POST', "/api/v1/captain-reports/{$report['id']}/submit");
        $this->as($this->b)->api('POST', "/api/v1/captain-reports/{$report['id']}/verify", ['apply_to_port_call' => true]);
        $this->as($this->a);
        $move('discharging', '2026-10-04T14:00');
        $rt = $this->api('GET', "/api/v1/voyages/{$v}")['port_calls'][1];
        if (empty($rt['ata'])) {
            throw new RuntimeException('The captain report did not set the Ras Tanura ATA.');
        }
        $rt = $this->api('PUT', "/api/v1/voyages/{$v}/port-calls/{$rt['id']}", ['lock_version' => $rt['lock_version'], 'atb' => '2026-10-04T14:00', 'atd' => '2026-10-07T06:00']);

        // ---- 8. Port DAs (final): A creates and submits, B approves
        $cat = fn (string $code) => DaCostCategory::query()->where('code', $code)->value('id');
        $makeDa = function (array $call, int $portId, array $items) use ($v): void {
            $da = $this->api('POST', '/api/v1/port-das', ['voyage_id' => $v, 'port_call_id' => $call['id'], 'port_id' => $portId, 'da_type' => 'final', 'currency' => 'USD'], 201);
            $this->api('POST', "/api/v1/port-das/{$da['id']}/items", ['items' => $items]);
            $this->api('POST', "/api/v1/port-das/{$da['id']}/submit");
            $this->as($this->b)->api('POST', "/api/v1/port-das/{$da['id']}/approve");
            $this->as($this->a);
        };
        $makeDa($ja, $jebel->id, [
            ['da_cost_category_id' => $cat('PILOT'), 'description' => 'Pilotage', 'estimated_amount' => '1500', 'actual_amount' => '1800'],
            ['da_cost_category_id' => $cat('BERTH'), 'description' => 'Berth / dockage', 'estimated_amount' => '2500', 'actual_amount' => '2500'],
            ['da_cost_category_id' => $cat('AGENCY'), 'description' => 'Agency fee', 'estimated_amount' => '600', 'actual_amount' => '600'],
        ]);
        $makeDa($rt, $ras->id, [
            ['da_cost_category_id' => $cat('PILOT'), 'description' => 'Pilotage', 'estimated_amount' => '1800', 'actual_amount' => '2000'],
            ['da_cost_category_id' => $cat('TOWAGE'), 'description' => 'Towage', 'estimated_amount' => '1000', 'actual_amount' => '1200'],
            ['da_cost_category_id' => $cat('BERTH'), 'description' => 'Berth / dockage', 'estimated_amount' => '1700', 'actual_amount' => '1600'],
            ['da_cost_category_id' => $cat('AGENCY'), 'description' => 'Agency fee', 'estimated_amount' => '600', 'actual_amount' => '600'],
        ]);

        // ---- 9. Laytime at Ras Tanura: demurrage 4,000.00 (56 h used against 48 h allowed, 4 h of rain excluded)
        $lt = $this->api('POST', '/api/v1/laytime-calculations', ['voyage_id' => $v, 'port_call_id' => $rt['id'], 'calculation_type' => 'discharge', 'fixed_hours' => '48',
            'demurrage_rate_per_day' => '12000', 'despatch_rate_per_day' => '6000', 'currency' => 'USD', 'once_on_demurrage_rule' => 'always_on_demurrage',
            'nor_tendered_at' => '2026-10-04T12:45', 'nor_accepted_at' => '2026-10-04T13:00', 'laytime_commenced_at' => '2026-10-04T16:00',
            'laytime_completed_at' => '2026-10-07T04:00'], 201);
        $lurl = "/api/v1/laytime-calculations/{$lt['id']}";
        foreach ([['ARRIVED', '2026-10-04T12:30', 'Arrived Ras Tanura anchorage'], ['NOR_TENDERED', '2026-10-04T12:45', 'NOR tendered'], ['ALL_FAST', '2026-10-04T14:00', 'All fast'],
            ['COMMENCED_DISCHARGING', '2026-10-04T16:00', 'Commenced discharging'], ['COMPLETED_DISCHARGING', '2026-10-07T04:00', 'Completed discharging'],
            ['SAILED', '2026-10-07T06:00', 'Sailed']] as [$code, $at, $d]) {
            $this->api('POST', "{$lurl}/sof-events", ['event_code' => $code, 'event_at' => $at, 'description' => $d], 201);
        }
        $this->api('POST', "{$lurl}/exceptions", ['from_at' => '2026-10-05T02:00', 'to_at' => '2026-10-05T06:00', 'exception_type' => 'weather', 'pct_counted' => '0', 'remarks' => 'Rain'], 201);
        $calc = $this->api('POST', "{$lurl}/calculate");
        $this->expect('4000.00', $calc['demurrage_amount'], 'demurrage');
        $this->api('POST', "{$lurl}/submit");
        $this->as($this->b)->api('POST', "{$lurl}/agree");
        $this->as($this->a);

        $this->api('POST', "/api/v1/voyages/{$v}/complete", ['at' => '2026-10-07T08:00']);

        // ---- 10. Finance: freight and bunkers, approvals, invoice, payments, supplier payable
        $freight = $this->api('POST', '/api/v1/voyage-revenues', ['voyage_id' => $v, 'contract_id' => $cid,
            'revenue_category_id' => RevenueCategory::query()->where('code', 'FREIGHT')->value('id'), 'description' => 'Freight — Jebel Ali to Ras Tanura',
            'quantity' => '2000', 'rate' => '55', 'currency' => 'USD', 'commission_pct_total' => '5'], 201);
        $this->api('POST', "/api/v1/voyage-revenues/{$freight['id']}/confirm");

        $bunker = $this->api('POST', '/api/v1/voyage-expenses', ['voyage_id' => $v, 'contract_id' => $cid,
            'expense_category_id' => ExpenseCategory::query()->where('code', 'BUNKER')->value('id'), 'description' => 'Bunkers — 44 MT HFO @ 600 (BDN-0001)',
            'quantity' => '44', 'rate' => '600', 'currency' => 'USD', 'supplier_company_id' => $supplier->id, 'incurred_at' => '2026-10-02'], 201);
        $this->api('POST', "/api/v1/voyage-expenses/{$bunker['id']}/confirm");
        $this->as($this->b)->api('POST', "/api/v1/voyage-expenses/{$bunker['id']}/approve");
        $this->as($this->a);
        // The Port DA expense lines were created by B's approval, so A approves them.
        foreach ($this->api('GET', "/api/v1/voyage-expenses?voyage_id={$v}&per_page=50") as $e) {
            if ($e['status'] === 'confirmed') {
                $this->api('POST', "/api/v1/voyage-expenses/{$e['id']}/approve");
            }
        }

        $revIds = collect($this->api('GET', "/api/v1/voyage-revenues?voyage_id={$v}&status=confirmed&per_page=50"))->pluck('id')->all();
        $this->expect('2', (string) count($revIds), 'confirmed revenue lines (freight and demurrage)');
        $inv = $this->api('POST', '/api/v1/invoices', ['invoice_type' => 'freight', 'customer_company_id' => $gulf->id, 'contract_id' => $cid, 'voyage_id' => $v,
            'issue_date' => '2026-10-08', 'due_date' => '2026-11-07', 'currency' => 'USD', 'revenue_ids' => $revIds], 201);
        $inv = $this->api('POST', "/api/v1/invoices/{$inv['id']}/issue");
        $this->expect('114000.00', $inv['total'], 'invoice total');
        $pay = $this->api('POST', '/api/v1/payments', ['direction' => 'received', 'currency' => 'USD', 'company_id' => $gulf->id, 'payment_date' => '2026-10-09',
            'amount' => '114000', 'method' => 'wire', 'bank_reference' => 'TT-998877'], 201);
        $this->api('POST', "/api/v1/payments/{$pay['id']}/allocate", ['allocations' => [['invoice_id' => $inv['id'], 'amount' => '114000']]]);

        $payable = $this->api('POST', '/api/v1/payables', ['supplier_company_id' => $supplier->id, 'supplier_invoice_ref' => 'OF-INV-7788', 'currency' => 'USD',
            'issue_date' => '2026-10-02', 'due_date' => '2026-10-30', 'subtotal' => '26400', 'tax' => '0'], 201);
        $this->as($this->b)->api('POST', "/api/v1/payables/{$payable['id']}/approve");
        $this->as($this->a);
        $out = $this->api('POST', '/api/v1/payments', ['direction' => 'paid', 'currency' => 'USD', 'company_id' => $supplier->id, 'payment_date' => '2026-10-09',
            'amount' => '26400', 'method' => 'wire', 'bank_reference' => 'TT-OUT-5521'], 201);
        $this->api('POST', "/api/v1/payments/{$out['id']}/allocate", ['allocations' => [['payable_id' => $payable['id'], 'amount' => '26400']]]);

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
        $this->expect('114000.00', $rows['gross_revenue']['actual'], 'actual revenue');
        $this->expect('36700.00', $rows['total_costs']['actual'], 'actual expenses');
        $this->expect('71800.00', $rows['net_profit']['actual'], 'net profit');
        $this->expect('62.98', $pnl['margin_pct'], 'margin');

        return [
            'Enquiry' => $enq['enquiry_number'],
            'Voyage' => $final['voyage_number'].' ('.$final['status'].')',
            'Invoice' => $inv['invoice_number'].' · '.$inv['total'].' USD',
            'Estimated net profit' => $rows['net_profit']['estimate'],
            'Actual net profit' => $rows['net_profit']['actual'].' ('.$pnl['margin_pct'].' % margin)',
            'Variance' => $rows['net_profit']['variance'].' ('.$rows['net_profit']['variance_pct'].' %)',
        ];
    }
}
