<?php

// Times the main endpoints (first and second call, query count) against the database filled by load.php.
// Usage: DB_DATABASE=offshore_load php -d memory_limit=2G tools/loadtest/time.php

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (DB::getDatabaseName() !== 'offshore_load') {
    fwrite(STDERR, 'Refusing: '.DB::getDatabaseName()."\n");
    exit(1);
}
// Latest position per vessel + AIS switched on, as in production use.
DB::statement('SET FOREIGN_KEY_CHECKS=0');
DB::table('vessel_latest_positions')->truncate();
DB::statement('SET FOREIGN_KEY_CHECKS=1');
DB::statement('INSERT INTO vessel_latest_positions (vessel_id, ais_position_id, observed_at, is_stale, created_at, updated_at)
  SELECT p.vessel_id, p.id, p.observed_at, 0, NOW(), NOW() FROM ais_positions p JOIN (SELECT vessel_id, MAX(observed_at) m FROM ais_positions GROUP BY vessel_id) x ON x.vessel_id=p.vessel_id AND x.m=p.observed_at');
DB::table('settings')->updateOrInsert(['key' => 'ais.enabled'], ['value' => '1', 'type' => 'bool', 'group' => 'ais']);
Cache::flush();

$user = User::query()->firstOrFail();
Sanctum::actingAs($user);
$vessel = DB::table('vessels')->value('id');
$from = date('Y-m-d', strtotime('-30 days'));
$urls = array_filter(explode("\n", <<<U
/api/v1/dashboard
/api/v1/invoices?per_page=15
/api/v1/invoices?per_page=15&status=overdue
/api/v1/invoices?per_page=15&search=INV-L-0123
/api/v1/payables?per_page=15&status=approved
/api/v1/payments?per_page=15
/api/v1/voyage-revenues?per_page=15&status=confirmed
/api/v1/voyage-expenses?per_page=15
/api/v1/voyages?per_page=15
/api/v1/voyages?per_page=15&status=sailing
/api/v1/captain-reports?per_page=15
/api/v1/documents?per_page=15
/api/v1/documents?per_page=15&expiry=30
/api/v1/documents?per_page=15&search=doc123
/api/v1/receivables/aging
/api/v1/balancing/accounts
/api/v1/balancing/cash-flow
/api/v1/reports/voyage-pnl
/api/v1/reports/estimated-vs-actual
/api/v1/reports/vessel-profitability
/api/v1/reports/outstanding-invoices
/api/v1/reports/revenue
/api/v1/reports/vessel-utilization
/api/v1/reports/vessel-performance
/api/v1/reports/bunker-consumption
/api/v1/statistics/revenue?group_by=month
/api/v1/statistics/revenue?group_by=customer
/api/v1/statistics/avg-voyage-profit?group_by=vessel
/api/v1/ais/fleet
/api/v1/ais/vessels/{$vessel}/track?from={$from}
/api/v1/ais/vessels/{$vessel}/positions?per_page=15
/api/v1/audit-logs?per_page=15
U));

printf("%-72s %7s %6s %9s\n", 'endpoint', 'ms(1st)', 'ms(2nd)', 'queries');
foreach ($urls as $url) {
    $r = [];
    foreach ([1, 2] as $run) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $t = microtime(true);
        $resp = $app->handle(Request::create($url, 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/json']));
        $ms = (microtime(true) - $t) * 1000;
        $r[] = [$ms, count(DB::getQueryLog()), $resp->getStatusCode(), strlen($resp->getContent())];
        DB::disableQueryLog();
    }
    printf("%-72s %7.0f %6.0f %6d%s\n", $url, $r[0][0], $r[1][0], $r[1][1], $r[1][2] === 200 ? '' : "  !! HTTP {$r[1][2]}");
}
