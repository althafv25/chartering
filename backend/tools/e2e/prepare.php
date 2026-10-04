<?php

// Creates (if needed) and rebuilds the throw-away database used by the browser tests, then seeds it.
// It refuses to touch any database that is not named offshore_e2e.
//   DB_DATABASE=offshore_e2e ADMIN_EMAIL=... ADMIN_PASSWORD=... php tools/e2e/prepare.php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$name = (string) config('database.connections.mysql.database');
if ($name !== 'offshore_e2e') {
    fwrite(STDERR, "Refusing: database is '{$name}', expected 'offshore_e2e'.\n");
    exit(1);
}

config(['database.connections.mysql.database' => null]);
DB::purge('mysql');
DB::statement('CREATE DATABASE IF NOT EXISTS `offshore_e2e` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
config(['database.connections.mysql.database' => 'offshore_e2e']);
DB::purge('mysql');

Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]);
// Fixture the API cannot create cheaply (a voyage normally comes from a whole chartering chain): one vessel, one open voyage, one port call.
$now = now();
$portId = DB::table('ports')->insertGetId(['name' => 'Jebel Ali', 'normalized_name' => 'jebel ali', 'unlocode' => 'AEJEA', 'country' => 'AE', 'timezone' => 'Asia/Dubai', 'created_at' => $now, 'updated_at' => $now]);
$vesselId = DB::table('vessels')->insertGetId(['code' => 'E2EV', 'name' => 'MV Voyager', 'vessel_type_id' => DB::table('vessel_types')->value('id'), 'created_at' => $now, 'updated_at' => $now]);
$voyageId = DB::table('voyages')->insertGetId([
    'voyage_number' => 'E2E-26-001', 'vessel_id' => $vesselId, 'conversion_type' => 'direct_estimation', 'operation_type' => 'voyage', 'currency' => 'USD',
    'status' => 'sailing', 'commenced_at' => $now->copy()->subDays(10), 'created_at' => $now, 'updated_at' => $now,
]);
DB::table('port_calls')->insert(['voyage_id' => $voyageId, 'sequence' => 1, 'port_id' => $portId, 'purpose' => 'load', 'status' => 'planned', 'created_at' => $now, 'updated_at' => $now]);
echo "prepared offshore_e2e\n";
