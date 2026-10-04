<?php

namespace Tests\Feature\Chartering;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\FuelType;
use App\Models\Port;
use App\Models\PortDistance;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselType;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

abstract class CharteringTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $charterer;

    protected User $manager;

    protected Vessel $vessel;

    protected Company $client;

    protected int $mgo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
        $this->seed(ReferenceDataSeeder::class);

        $this->charterer = $this->userWithRole(UserRole::Chartering);
        $this->manager = $this->userWithRole(UserRole::Management);
        $this->client = Company::query()->create(['code' => 'C1', 'legal_name' => 'Gulf Charterers', 'normalized_name' => 'gulf charterers']);
        $this->client->roles()->create(['role' => 'charterer']);
        $this->mgo = FuelType::query()->where('code', 'MGO')->value('id');

        $this->vessel = Vessel::query()->create(['code' => 'GMS1', 'name' => 'GMS Endeavour', 'vessel_type_id' => VesselType::query()->value('id'), 'service_speed_kn' => '12']);
        $profile = $this->vessel->consumptionProfiles()->create(['name' => 'Design', 'effective_from' => '2026-01-01', 'is_default' => true]);
        foreach ([['sea_laden', '12', '10'], ['sea_ballast', '12', '9'], ['port_working', '0', '3'], ['port_idle', '0', '1.5'], ['dp_operation', '0', '7.5'], ['standby', '0', '2.5']] as [$mode, $speed, $mt]) {
            $profile->rates()->create(['mode' => $mode, 'speed_kn' => $speed, 'fuel_type_id' => $this->mgo, 'consumption_mt_per_day' => $mt]);
        }

        $a = Port::query()->create(['name' => 'Jebel Ali', 'normalized_name' => 'jebel ali', 'unlocode' => 'AEJEA', 'country' => 'AE', 'timezone' => 'Asia/Dubai']);
        $b = Port::query()->create(['name' => 'Ras Tanura', 'normalized_name' => 'ras tanura', 'unlocode' => 'SARTA', 'country' => 'SA', 'timezone' => 'Asia/Riyadh']);
        PortDistance::query()->create(['from_type' => 'port', 'from_id' => $a->id, 'to_type' => 'port', 'to_id' => $b->id, 'distance_nm' => '330', 'provider' => 'manual', 'calculated_at' => now()]);
        $this->ports = [$a->id, $b->id];
    }

    /** @var array{0:int,1:int} */
    protected array $ports;

    protected function as(User $user): static
    {
        Sanctum::actingAs($user);

        return $this;
    }

    protected function enquiry(array $override = []): int
    {
        $this->as($this->charterer);

        return $this->postJson('/api/v1/enquiries', [
            'business_type' => 'voyage_charter', 'charterer_company_id' => $this->client->id, 'cargo_description' => 'Pipes',
            'quantity' => '2000', 'quantity_unit' => 'mt', 'rate_idea' => '55', 'rate_basis' => 'per_mt', 'currency' => 'USD',
            'ports' => [['port_id' => $this->ports[0], 'purpose' => 'load'], ['port_id' => $this->ports[1], 'purpose' => 'discharge']],
            ...$override,
        ])->assertCreated()->json('data.id');
    }

    /** @return array{0:int,1:int} [estimation id, scenario A id] */
    protected function estimation(?int $enquiryId = null, array $override = []): array
    {
        $enquiryId ??= $this->enquiry();
        $res = $this->as($this->charterer)->postJson('/api/v1/estimations', ['enquiry_id' => $enquiryId, 'vessel_id' => $this->vessel->id, ...$override])->assertCreated();

        return [$res->json('data.id'), $res->json('data.scenarios.0.id')];
    }

    /** Fills the missing price/port days so scenario A calculates. @return array<string, mixed> response data */
    protected function completeScenario(int $estId, int $scenarioId, array $patch = []): array
    {
        $s = $this->getJson("/api/v1/estimations/{$estId}/scenarios/{$scenarioId}")->json('data');
        $in = $s['inputs'];
        $in['fuel_prices'][0]['price_per_mt'] = '800';
        $in['calls'][0]['working_days'] = '1.5';
        $in['calls'][1]['working_days'] = '1';
        $in = array_replace_recursive($in, $patch);

        return $this->putJson("/api/v1/estimations/{$estId}/scenarios/{$scenarioId}", ['lock_version' => $s['lock_version'], 'inputs' => $in])->assertOk()->json('data');
    }

    /** Draft → selected → submitted (charterer) → approved (manager). */
    protected function approvedEstimation(?int $enquiryId = null): array
    {
        [$est, $sc] = $this->estimation($enquiryId);
        $this->completeScenario($est, $sc);
        $this->postJson("/api/v1/estimations/{$est}/scenarios/{$sc}/select")->assertOk();
        $this->postJson("/api/v1/estimations/{$est}/submit")->assertOk();
        $this->as($this->manager)->postJson("/api/v1/estimations/{$est}/approve")->assertOk();
        $this->as($this->charterer);

        return [$est, $sc];
    }
}
