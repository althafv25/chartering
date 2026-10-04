<?php

namespace Tests\Feature\Ais;

use App\Enums\UserRole;
use App\Models\AisPosition;
use App\Models\UserNotification;
use App\Models\VesselLatestPosition;
use App\Services\Ais\AisPositionDTO;
use App\Services\Ais\AisProviderInterface;
use App\Services\Ais\AisService;
use App\Services\Ais\ProviderHealth;
use App\Services\Ais\VesselIdentifier;
use App\Services\SettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Operations\OperationsTestCase;

class AisTest extends OperationsTestCase
{
    private function enable(): void
    {
        app(SettingsService::class)->update(['ais.enabled' => true], $this->userWithRole(UserRole::SuperAdmin));
    }

    /** @param array<string, mixed> $extra */
    private function manual(array $extra = []): TestResponse
    {
        return $this->as($this->ops)->postJson('/api/v1/ais/positions/manual', ['vessel_id' => $this->vessel->id, 'latitude' => '25.2', 'longitude' => '55.3', 'observed_at' => '2026-10-20T10:00:00Z', ...$extra]);
    }

    public function test_everything_but_status_is_unavailable_while_disabled(): void
    {
        $this->as($this->ops)->getJson('/api/v1/ais/status')->assertOk()->assertJsonPath('data.enabled', false)->assertJsonPath('data.provider', 'none');
        $this->as($this->ops)->getJson('/api/v1/ais/fleet')->assertStatus(409)->assertJsonPath('error_code', 'ais_disabled');
        $this->manual()->assertStatus(409)->assertJsonPath('error_code', 'ais_disabled');
    }

    public function test_manual_positions_update_latest_only_when_newer_and_reject_bad_input(): void
    {
        $this->enable();
        $this->manual(['observed_at' => '2026-10-20T10:00:00Z', 'sog_kn' => '11.5'])->assertCreated()->assertJsonPath('data.provider', 'manual')->assertJsonPath('data.sog_kn', '11.50');
        // An older observation is stored but does not replace the latest one.
        $this->manual(['observed_at' => '2026-10-20T08:00:00Z', 'latitude' => '24.0'])->assertCreated();
        $this->assertSame(2, AisPosition::query()->count());
        $this->assertSame('25.200000', VesselLatestPosition::query()->firstOrFail()->position->latitude);

        $this->manual(['observed_at' => '2026-10-20T10:00:00Z'])->assertStatus(409)->assertJsonPath('error_code', 'duplicate_position');
        $this->manual(['observed_at' => '2026-10-21T10:00:00Z'])->assertStatus(422);
        $this->manual(['latitude' => '91'])->assertStatus(422);
        $this->manual(['longitude' => '-181'])->assertStatus(422);
        // Chartering can view AIS but not record positions.
        $this->as($this->charterer)->postJson('/api/v1/ais/positions/manual', ['vessel_id' => $this->vessel->id, 'latitude' => '1', 'longitude' => '1', 'observed_at' => '2026-10-20T09:00:00Z'])->assertForbidden();
        $this->as($this->charterer)->getJson('/api/v1/ais/fleet')->assertOk();
    }

    public function test_fleet_shows_voyage_and_flags_stale_positions(): void
    {
        $this->enable();
        $voyage = $this->sailingVoyage();
        $this->manual(['observed_at' => '2026-10-20T10:30:00Z'])->assertCreated();   // test clock is 12:00 → 1.5 h old

        $fleet = $this->as($this->ops)->getJson('/api/v1/ais/fleet')->assertOk()->json('data');
        $this->assertCount(1, $fleet);
        $this->assertFalse($fleet[0]['is_stale']);
        $this->assertSame($voyage, $fleet[0]['voyage']['id']);

        app(SettingsService::class)->update(['ais.stale_hours' => 1], $this->userWithRole(UserRole::SuperAdmin));
        $this->assertTrue($this->as($this->ops)->getJson('/api/v1/ais/fleet')->json('data.0.is_stale'));
    }

    public function test_track_distance_window_limit_and_positions_list(): void
    {
        $this->enable();
        foreach ([['2026-10-20T06:00:00Z', '25.0'], ['2026-10-20T07:00:00Z', '26.0']] as [$at, $lat]) {
            $this->manual(['observed_at' => $at, 'latitude' => $lat, 'longitude' => '55.0'])->assertCreated();
        }
        $track = $this->as($this->ops)->getJson("/api/v1/ais/vessels/{$this->vessel->id}/track?from=2026-10-20T00:00:00Z&to=2026-10-20T12:00:00Z")->assertOk()->json('data');
        $this->assertSame(2, $track['point_count']);
        $this->assertSame('60.04', $track['distance_nm']);   // 1° of latitude ≈ 60.04 nm
        $this->assertSame('25.000000', $track['points'][0]['latitude']);

        $this->as($this->ops)->getJson("/api/v1/ais/vessels/{$this->vessel->id}/track?from=2026-09-01T00:00:00Z&to=2026-10-20T00:00:00Z")->assertStatus(422)->assertJsonPath('error_code', 'track_window_too_large');
        $this->as($this->ops)->getJson("/api/v1/ais/vessels/{$this->vessel->id}/track?from=2026-10-20T10:00:00Z&to=2026-10-20T09:00:00Z")->assertStatus(422);

        $list = $this->as($this->ops)->getJson("/api/v1/ais/vessels/{$this->vessel->id}/positions")->assertOk();
        $list->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.latitude', '26.000000');  // newest first
    }

    public function test_ingest_command_is_idempotent_and_survives_provider_failure(): void
    {
        $this->enable();
        $this->sailingVoyage();
        $vesselId = $this->vessel->id;
        $fake = new class($vesselId) implements AisProviderInterface
        {
            public bool $fail = false;

            public function __construct(private int $vesselId) {}

            public function name(): string
            {
                return 'fake';
            }

            public function latestPositions(array $vessels): array
            {
                if ($this->fail) {
                    throw new \RuntimeException('provider down');
                }
                $this->seen = array_map(fn (VesselIdentifier $v) => $v->vesselId, $vessels);

                return [new AisPositionDTO($this->vesselId, 'fake', '25.100000', '55.200000', CarbonImmutable::parse('2026-10-20T11:00:00Z'), '10.00')];
            }

            /** @var list<int> */
            public array $seen = [];

            public function history(VesselIdentifier $vessel, CarbonImmutable $from, CarbonImmutable $to): array
            {
                return [];
            }

            public function healthCheck(): ProviderHealth
            {
                return new ProviderHealth(true, 'ok');
            }
        };
        $this->app->instance(AisProviderInterface::class, $fake);

        $this->assertSame(0, Artisan::call('ais:ingest'));
        $this->assertStringContainsString('Ingested 1 new position', Artisan::output());
        $this->assertSame([$vesselId], $fake->seen);
        Artisan::call('ais:ingest');
        $this->assertStringContainsString('Ingested 0 new position', Artisan::output());
        $this->assertSame(1, AisPosition::query()->count());

        $fake->fail = true;
        $this->assertSame(1, Artisan::call('ais:ingest'));
        $this->assertStringContainsString('provider down', Artisan::output());
    }

    public function test_stale_check_notifies_once_per_day_for_vessels_on_active_voyages(): void
    {
        $this->enable();
        $this->sailingVoyage();   // active voyage, no position yet

        Artisan::call('ais:check-stale');
        $first = UserNotification::query()->where('user_id', $this->ops->id)->where('category', 'ais_stale')->count();
        Artisan::call('ais:check-stale');
        $this->assertSame(1, $first);
        $this->assertSame($first, UserNotification::query()->where('user_id', $this->ops->id)->where('category', 'ais_stale')->count());

        $this->manual(['observed_at' => '2026-10-20T11:30:00Z'])->assertCreated();
        $this->assertSame([], app(AisService::class)->staleVessels());
    }
}
