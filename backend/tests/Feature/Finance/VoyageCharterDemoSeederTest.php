<?php

namespace Tests\Feature\Finance;

use App\Models\Contract;
use App\Models\Enquiry;
use App\Models\Estimation;
use App\Models\Fixture;
use App\Models\Invoice;
use App\Models\User;
use App\Models\Voyage;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\VoyageCharterDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** The voyage charter demo seeder builds a whole voyage charter through the real workflow and ends with the documented P&L. */
class VoyageCharterDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_a_finalized_voyage_charter_with_the_documented_profit_and_loss_and_is_idempotent(): void
    {
        Carbon::setTestNow('2026-10-10 12:00:00');
        $this->seed(DatabaseSeeder::class);
        $this->seed(VoyageCharterDemoSeeder::class);

        $this->assertSame([1, 1, 1, 1, 1, 1], [Enquiry::query()->count(), Estimation::query()->count(), Fixture::query()->count(), Contract::query()->count(),
            Voyage::query()->count(), Invoice::query()->count()]);

        $voyage = Voyage::query()->firstOrFail();
        $this->assertSame(['voyage', 'finalized'], [$voyage->operation_type, $voyage->status]);

        Sanctum::actingAs(User::query()->where('email', 'admin@offshore.local')->firstOrFail());
        $pnl = $this->getJson("/api/v1/voyages/{$voyage->id}/financials")->assertOk()->json('data');
        $rows = collect($pnl['rows'])->keyBy('metric');
        $this->assertSame(['71050.00', '71800.00', '750.00'], [$rows['net_profit']['estimate'], $rows['net_profit']['actual'], $rows['net_profit']['variance']]);
        $this->assertSame(['110000.00', '114000.00'], [$rows['gross_revenue']['estimate'], $rows['gross_revenue']['actual']]);
        $this->assertSame(['33450.00', '36700.00'], [$rows['total_costs']['estimate'], $rows['total_costs']['actual']]);
        $this->assertSame(['77300.00', '62.98'], [$pnl['gross_profit'], $pnl['margin_pct']]);

        // A second run does nothing.
        $this->seed(VoyageCharterDemoSeeder::class);
        $this->assertSame(1, Voyage::query()->count());
    }
}
