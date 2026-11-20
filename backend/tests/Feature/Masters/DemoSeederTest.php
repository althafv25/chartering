<?php

namespace Tests\Feature\Masters;

use App\Enums\ConsumptionMode;
use App\Enums\UserRole;
use App\Models\Contract;
use App\Models\Enquiry;
use App\Models\Estimation;
use App\Models\Fixture;
use App\Models\Offer;
use App\Models\OffshoreLocation;
use App\Models\Port;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselConsumptionRate;
use App\Models\Voyage;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The demo seeder provides master data only. Its vessel profiles bypass request validation, so they must use the modes an
 * estimation accepts, otherwise saving an estimation for a seeded vessel fails with "inputs.consumption.0.mode is invalid".
 */
class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_seeds_master_data_but_no_enquiry_estimation_offer_fixture_contract_or_voyage(): void
    {
        $this->assertGreaterThan(0, Vessel::query()->count());
        $this->assertSame([0, 0, 0, 0, 0, 0], [Enquiry::query()->count(), Estimation::query()->count(), Offer::query()->count(),
            Fixture::query()->count(), Contract::query()->count(), Voyage::query()->count()]);
    }

    public function test_seeded_consumption_profiles_only_use_modes_an_estimation_accepts(): void
    {
        $modes = VesselConsumptionRate::query()->distinct()->pluck('mode')->all();
        $this->assertNotEmpty($modes);
        $this->assertSame([], array_values(array_diff($modes, ConsumptionMode::values())));
    }

    public function test_an_estimation_for_a_seeded_vessel_can_be_saved(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $user->assignRole(UserRole::Chartering->value);

        $from = Port::query()->where('unlocode', 'AEFJR')->firstOrFail();
        $field = OffshoreLocation::query()->where('code', 'DEMO-ALPHA')->firstOrFail();
        $enquiry = $this->postJson('/api/v1/enquiries', ['business_type' => 'offshore_charter', 'rate_basis' => 'per_day', 'rate_idea' => '14500', 'currency' => 'USD',
            'ports' => [['port_id' => $from->id, 'purpose' => 'load'], ['offshore_location_id' => $field->id, 'purpose' => 'offshore_ops']]])->assertCreated()->json('data.id');

        $vessel = Vessel::query()->where('code', 'PSV001')->firstOrFail();
        $res = $this->postJson('/api/v1/estimations', ['enquiry_id' => $enquiry, 'vessel_id' => $vessel->id])->assertCreated();
        $est = $res->json('data.id');
        $sc = $res->json('data.scenarios.0.id');
        $scenario = $this->getJson("/api/v1/estimations/{$est}/scenarios/{$sc}")->json('data');

        // Saving the inputs exactly as the system filled them in is what used to fail on the consumption mode.
        $this->putJson("/api/v1/estimations/{$est}/scenarios/{$sc}", ['lock_version' => $scenario['lock_version'], 'inputs' => $scenario['inputs']])->assertOk();
    }
}
