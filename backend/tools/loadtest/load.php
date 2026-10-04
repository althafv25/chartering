<?php

// Load-test data generator: ~790,000 rows (60k invoices, 160k ledger lines, 400k AIS positions, 300k audit entries ...).
// Usage (scratch database ONLY; the script refuses any database not named offshore_load):
//   mysql -e 'CREATE DATABASE offshore_load'
//   DB_DATABASE=offshore_load ADMIN_EMAIL=load@example.test ADMIN_PASSWORD='...' php artisan migrate --force && php artisan db:seed --force
//   DB_DATABASE=offshore_load php -d memory_limit=2G tools/loadtest/load.php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (DB::getDatabaseName() !== 'offshore_load') {
    fwrite(STDERR, 'Refusing: database is '.DB::getDatabaseName()."\n");
    exit(1);
}
DB::disableQueryLog();
DB::statement('SET FOREIGN_KEY_CHECKS=0');
foreach (['activity_log', 'documents', 'ais_positions', 'vessel_latest_positions', 'captain_report_fuel_lines', 'captain_reports', 'voyage_expenses', 'voyage_revenues', 'payments', 'payables', 'invoices', 'voyages', 'vessels', 'companies'] as $t) {
    DB::table($t)->truncate();
}
DB::statement('SET FOREIGN_KEY_CHECKS=1');
mt_srand(7);
$now = date('Y-m-d H:i:s');
$ins = function (string $table, int $count, callable $row, int $chunk = 1000) {
    $t = microtime(true);
    for ($i = 0; $i < $count; $i += $chunk) {
        $rows = [];
        for ($j = $i; $j < min($count, $i + $chunk); $j++) {
            $rows[] = $row($j);
        }
        DB::table($table)->insert($rows);
    }
    printf("%-26s %7d rows  %.1fs\n", $table, $count, microtime(true) - $t);
};
$pick = fn (array $a) => $a[mt_rand(0, count($a) - 1)];
$day = fn (int $back) => date('Y-m-d', strtotime("-{$back} days"));
$ts = fn (int $backDays, int $sec = 0) => date('Y-m-d H:i:s', time() - $backDays * 86400 - $sec);

$typeId = DB::table('vessel_types')->value('id');
$revCat = DB::table('revenue_categories')->pluck('id')->all();
$expCat = DB::table('expense_categories')->pluck('id')->all();
$fuel = DB::table('fuel_types')->pluck('id')->all();
$docType = DB::table('document_types')->pluck('id')->all();
$user = DB::table('users')->value('id');

$ins('companies', 3000, fn ($i) => ['code' => "C{$i}", 'legal_name' => "Company {$i} Shipping LLC", 'normalized_name' => "company {$i} shipping llc", 'created_at' => $now, 'updated_at' => $now]);
$ins('vessels', 40, fn ($i) => ['code' => "V{$i}", 'name' => "Vessel {$i}", 'vessel_type_id' => $typeId, 'service_speed_kn' => 12, 'created_at' => $now, 'updated_at' => $now]);
$companies = DB::table('companies')->pluck('id')->all();
$vessels = DB::table('vessels')->pluck('id')->all();

$statuses = ['completed', 'finalized', 'sailing', 'offshore_operation', 'completed', 'finalized', 'cancelled', 'draft'];
$ins('voyages', 4000, function ($i) use ($vessels, $companies, $statuses, $pick, $ts, $now) {
    $status = $pick($statuses);
    $start = mt_rand(5, 700);

    return ['voyage_number' => 'V-'.str_pad((string) $i, 5, '0', STR_PAD_LEFT), 'vessel_id' => $pick($vessels), 'conversion_type' => 'direct_estimation', 'operation_type' => 'voyage', 'currency' => 'USD', 'status' => $status,
        'charterer_company_id' => $pick($companies), 'commenced_at' => $status === 'draft' ? null : $ts($start), 'completed_at' => in_array($status, ['completed', 'finalized'], true) ? $ts(max(1, $start - mt_rand(3, 30))) : null,
        'created_at' => $ts($start + 2), 'updated_at' => $now];
});
$voyages = DB::table('voyages')->pluck('id')->all();

$inv = ['issued', 'paid', 'partially_paid', 'overdue', 'draft', 'paid', 'paid'];
$ins('invoices', 60000, function ($i) use ($companies, $voyages, $inv, $pick, $day, $now) {
    $total = mt_rand(1000, 90000).'.00';
    $status = $pick($inv);
    $paid = $status === 'paid' ? $total : ($status === 'partially_paid' ? number_format(mt_rand(100, 900), 2, '.', '') : '0.00');
    $due = mt_rand(-200, 90);

    return ['invoice_number' => $status === 'draft' ? null : 'INV-L-'.str_pad((string) $i, 6, '0', STR_PAD_LEFT), 'invoice_type' => 'freight', 'customer_company_id' => $pick($companies), 'billing_snapshot' => '{"name":"x"}', 'voyage_id' => $pick($voyages),
        'issue_date' => $day(abs($due) + 30), 'due_date' => date('Y-m-d', strtotime("{$due} days")), 'currency' => 'USD', 'fx_rate' => 1, 'subtotal' => $total, 'tax_amount' => '0.00', 'total' => $total, 'base_total' => $total,
        'amount_paid' => $paid, 'status' => $status, 'created_at' => $now, 'updated_at' => $now];
});
$ins('payables', 25000, fn ($i) => ['payable_number' => 'PAYB-L-'.str_pad((string) $i, 6, '0', STR_PAD_LEFT), 'supplier_company_id' => $pick($companies), 'supplier_invoice_ref' => "S{$i}", 'voyage_id' => $pick($voyages),
    'issue_date' => $day(40), 'due_date' => date('Y-m-d', strtotime(mt_rand(-100, 90).' days')), 'currency' => 'USD', 'fx_rate' => 1, 'subtotal' => '500.00', 'total' => '500.00', 'base_total' => '500.00',
    'amount_paid' => '0.00', 'status' => $pick(['approved', 'paid', 'draft', 'partially_paid']), 'created_at' => $now, 'updated_at' => $now]);
$ins('payments', 25000, fn ($i) => ['payment_number' => 'PAY-L-'.str_pad((string) $i, 6, '0', STR_PAD_LEFT), 'direction' => $pick(['received', 'paid']), 'company_id' => $pick($companies), 'payment_date' => $day(mt_rand(1, 400)),
    'amount' => '1000.00', 'currency' => 'USD', 'fx_rate' => 1, 'base_amount' => '1000.00', 'unallocated_amount' => '0.00', 'bank_reference' => "B{$i}", 'created_at' => $now, 'updated_at' => $now]);
$ins('voyage_revenues', 80000, fn ($i) => ['voyage_id' => $pick($voyages), 'revenue_category_id' => $pick($revCat), 'description' => "Rev {$i}", 'currency' => 'USD', 'fx_rate' => 1, 'amount' => '900.00', 'base_amount' => '900.00',
    'commission_amount' => '30.00', 'status' => $pick(['confirmed', 'invoiced', 'draft', 'confirmed']), 'created_at' => $ts(mt_rand(1, 700)), 'updated_at' => $now]);
$ins('voyage_expenses', 80000, fn ($i) => ['voyage_id' => $pick($voyages), 'expense_category_id' => $pick($expCat), 'description' => "Exp {$i}", 'currency' => 'USD', 'fx_rate' => 1, 'amount' => '400.00', 'base_amount' => '400.00',
    'supplier_company_id' => $pick($companies), 'status' => $pick(['confirmed', 'approved', 'paid', 'draft']), 'created_at' => $ts(mt_rand(1, 700)), 'updated_at' => $now]);
$ins('captain_reports', 40000, fn ($i) => ['vessel_id' => $pick($vessels), 'voyage_id' => $pick($voyages), 'report_type' => 'noon', 'reported_at' => $ts(mt_rand(1, 700), mt_rand(0, 86000)), 'status' => $pick(['verified', 'verified', 'draft']),
    'speed_kn' => 11.5, 'distance_since_last_nm' => 270.0, 'created_at' => $now, 'updated_at' => $now]);
$reportIds = DB::table('captain_reports')->pluck('id')->all();
$ins('captain_report_fuel_lines', count($reportIds), fn ($i) => ['captain_report_id' => $reportIds[$i], 'fuel_type_id' => $pick($fuel), 'rob_mt' => 100, 'consumed_mt' => 24, 'received_mt' => 0]);
$ins('ais_positions', 400000, fn ($i) => ['vessel_id' => $vessels[$i % count($vessels)], 'latitude' => 20 + ($i % 1000) / 100, 'longitude' => 50 + ($i % 1500) / 100, 'sog_kn' => 11.2, 'observed_at' => date('Y-m-d H:i:s', time() - (int) ($i / count($vessels)) * 300),
    'received_at' => $now, 'provider' => 'load', 'created_at' => $now, 'updated_at' => $now], 2000);
$ins('documents', 30000, fn ($i) => ['documentable_type' => $pick(['voyages', 'invoices', 'vessels', 'companies']), 'documentable_id' => mt_rand(1, 3000), 'document_type_id' => $pick($docType), 'title' => "Doc {$i}", 'disk' => 'documents',
    'path' => "x/{$i}.pdf", 'original_filename' => "doc{$i}.pdf", 'mime_type' => 'application/pdf', 'size_bytes' => 1000, 'sha256' => hash('sha256', (string) $i), 'expiry_date' => mt_rand(0, 3) ? null : date('Y-m-d', strtotime(mt_rand(-50, 300).' days')),
    'created_at' => $ts(mt_rand(1, 700)), 'updated_at' => $now]);
$ins('activity_log', 300000, fn ($i) => ['log_name' => 'default', 'description' => "event {$i}", 'subject_type' => 'invoices', 'subject_id' => mt_rand(1, 60000), 'event' => 'updated', 'causer_type' => 'users', 'causer_id' => $user,
    'created_at' => $ts(mt_rand(0, 700)), 'updated_at' => $now], 2000);
echo "done\n";
