<?php

namespace Tests\Feature\Finance;

use App\Models\Contract;
use App\Models\Enquiry;
use App\Models\Estimation;
use App\Models\Fixture;
use App\Models\Invoice;
use App\Models\OffshoreActivity;
use App\Models\OffshoreProject;
use App\Models\User;
use App\Models\Voyage;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\OffshoreCharterDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** The demo seeder builds a whole offshore charter through the real workflow and ends with the documented P&L. */
class OffshoreCharterDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_a_finalized_offshore_charter_with_the_documented_profit_and_loss_and_is_idempotent(): void
    {
        Carbon::setTestNow('2026-10-10 12:00:00');
        $this->seed(DatabaseSeeder::class);
        $this->seed(OffshoreCharterDemoSeeder::class);

        $this->assertSame([1, 1, 1, 1, 1, 7, 1], [Enquiry::query()->count(), Estimation::query()->count(), Fixture::query()->count(), Contract::query()->count(),
            OffshoreProject::query()->count(), OffshoreActivity::query()->where('status', 'verified')->count(), Invoice::query()->count()]);

        $voyage = Voyage::query()->firstOrFail();
        $this->assertSame(['offshore', 'finalized'], [$voyage->operation_type, $voyage->status]);

        Sanctum::actingAs(User::query()->where('email', 'admin@offshore.local')->firstOrFail());
        $pnl = $this->getJson("/api/v1/voyages/{$voyage->id}/financials")->assertOk()->json('data');
        $rows = collect($pnl['rows'])->keyBy('metric');
        $this->assertSame(['35068.75', '38450.00', '3381.25'], [$rows['net_profit']['estimate'], $rows['net_profit']['actual'], $rows['net_profit']['variance']]);
        $this->assertSame(['112000.00', '116000.00'], [$rows['gross_revenue']['estimate'], $rows['gross_revenue']['actual']]);
        $this->assertSame(['74231.25', '74700.00'], [$rows['total_costs']['estimate'], $rows['total_costs']['actual']]);
        $this->assertSame(['41300.00', '33.15'], [$pnl['gross_profit'], $pnl['margin_pct']]);

        // A second run does nothing.
        $this->seed(OffshoreCharterDemoSeeder::class);
        $this->assertSame(1, Voyage::query()->count());
    }
}
