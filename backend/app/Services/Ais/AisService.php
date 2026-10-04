<?php

namespace App\Services\Ais;

use App\Domain\Geo\GreatCircle;
use App\Enums\Permission;
use App\Exceptions\BusinessRuleException;
use App\Models\AisPosition;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselLatestPosition;
use App\Models\Voyage;
use App\Services\SettingsService;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * AIS queries and ingest (docs/09). Positions are advisory (AIS-01): nothing here touches captain
 * reports, milestones or statuses. Everything requires ais.enabled, except status().
 */
class AisService
{
    public const MAX_TRACK_DAYS = 31;

    public const MAX_TRACK_POINTS = 2000;

    /** Voyage statuses that count as "on an active voyage" (monitored and stale-checked). */
    private const INACTIVE_VOYAGE = ['draft', 'completed', 'finalized', 'cancelled'];

    public function __construct(private readonly AisProviderInterface $provider, private readonly SettingsService $settings) {}

    public function isEnabled(): bool
    {
        return (bool) $this->settings->get('ais.enabled', false);
    }

    public function staleHours(): int
    {
        return max(1, (int) $this->settings->get('ais.stale_hours', 6));
    }

    /** @return array<string, mixed> */
    public function status(User $actor): array
    {
        $this->authorize($actor, Permission::AisView);
        $latest = VesselLatestPosition::query();

        return [
            'enabled' => $this->isEnabled(),
            'provider' => $this->provider->name(),
            'health' => $this->provider->healthCheck()->toArray(),
            'stale_hours' => $this->staleHours(),
            'vessels_with_position' => (clone $latest)->count(),
            'last_received_at' => AisPosition::query()->max('received_at'),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function fleet(User $actor): array
    {
        $this->authorize($actor, Permission::AisView);
        $this->assertEnabled();

        $cutoff = now()->subHours($this->staleHours());
        $voyages = Voyage::query()->whereNotIn('status', self::INACTIVE_VOYAGE)->orderBy('id')->get(['id', 'vessel_id', 'voyage_number', 'status'])->keyBy('vessel_id');

        return VesselLatestPosition::query()->with(['vessel:id,code,name,status', 'position'])->get()
            ->filter(fn (VesselLatestPosition $l) => $l->vessel !== null && $l->position !== null && $l->vessel->status === 'active')
            ->map(function (VesselLatestPosition $l) use ($voyages, $cutoff) {
                $p = $l->position;
                $voyage = $voyages->get($l->vessel_id);

                return [
                    'vessel' => ['id' => $l->vessel->id, 'code' => $l->vessel->code, 'name' => $l->vessel->name],
                    'latitude' => $p->latitude, 'longitude' => $p->longitude, 'sog_kn' => $p->sog_kn, 'cog_deg' => $p->cog_deg, 'heading_deg' => $p->heading_deg,
                    'nav_status' => $p->nav_status, 'destination' => $p->destination, 'eta_reported' => $p->eta_reported?->toIso8601String(),
                    'observed_at' => $l->observed_at->toIso8601String(), 'provider' => $p->provider,
                    'is_stale' => $l->observed_at->lessThan($cutoff),
                    'voyage' => $voyage ? ['id' => $voyage->id, 'voyage_number' => $voyage->voyage_number, 'status' => $voyage->status] : null,
                ];
            })->sortBy(fn (array $r) => $r['vessel']['name'])->values()->all();
    }

    /** @return LengthAwarePaginator<int, AisPosition> */
    public function positions(User $actor, Vessel $vessel, int $perPage): LengthAwarePaginator
    {
        $this->authorize($actor, Permission::AisView);
        $this->assertEnabled();

        return AisPosition::query()->where('vessel_id', $vessel->id)->orderByDesc('observed_at')->paginate($perPage);
    }

    /**
     * Track for the map: at most 31 days, simplified to ≤ 2,000 points for display. The distance is
     * the great-circle sum over ALL stored points in the window (an estimate, never money).
     *
     * @return array<string, mixed>
     */
    public function track(User $actor, Vessel $vessel, ?CarbonImmutable $from, ?CarbonImmutable $to): array
    {
        $this->authorize($actor, Permission::AisView);
        $this->assertEnabled();

        $to ??= CarbonImmutable::now();
        $from ??= $to->subDay();
        if ($from->greaterThanOrEqualTo($to)) {
            throw new BusinessRuleException('The track start must be before its end.', 'validation_failed', ['from' => ['Must be before "to".']], 422);
        }
        if ($from->diffInDays($to) > self::MAX_TRACK_DAYS) {
            throw new BusinessRuleException('A track window is limited to '.self::MAX_TRACK_DAYS.' days.', 'track_window_too_large', ['from' => ['Maximum '.self::MAX_TRACK_DAYS.' days per request.']], 422);
        }

        // Raw rows, not models: a 31-day track is thousands of positions and only the simplified few are formatted.
        $rows = DB::table('ais_positions')->where('vessel_id', $vessel->id)->whereBetween('observed_at', [$from, $to])->orderBy('observed_at')->orderBy('id')
            ->get(['latitude', 'longitude', 'sog_kn', 'observed_at'])->values();

        $distance = '0.00';
        $coords = [];
        $previous = null;
        foreach ($rows as $row) {
            $lat = (float) $row->latitude;
            $lon = (float) $row->longitude;
            $coords[] = ['lat' => $lat, 'lon' => $lon];
            if ($previous !== null) {
                $distance = Decimal::add($distance, GreatCircle::distanceNm($previous[0], $previous[1], $lat, $lon));
            }
            $previous = [$lat, $lon];
        }

        $tz = (string) config('app.timezone');
        $points = array_map(fn (int $i) => [
            'latitude' => $rows[$i]->latitude, 'longitude' => $rows[$i]->longitude, 'sog_kn' => $rows[$i]->sog_kn,
            'observed_at' => CarbonImmutable::parse($rows[$i]->observed_at, $tz)->toIso8601String(),
        ], TrackSimplifier::simplifyIndexes($coords, self::MAX_TRACK_POINTS));

        return [
            'vessel_id' => $vessel->id, 'from' => $from->toIso8601String(), 'to' => $to->toIso8601String(),
            'point_count' => count($rows), 'returned_count' => count($points), 'distance_nm' => Decimal::round($distance, 2),
            'distance_basis' => 'great-circle sum of stored positions (estimate)', 'points' => $points,
        ];
    }

    /**
     * Manual / verified position entered by a user (ais.manual-position).
     *
     * @param  array<string, mixed>  $data
     */
    public function recordManual(User $actor, array $data): AisPosition
    {
        $this->authorize($actor, Permission::AisManualPosition);
        $this->assertEnabled();

        $vessel = Vessel::query()->findOrFail((int) $data['vessel_id']);
        $observed = CarbonImmutable::parse($data['observed_at']);
        if ($observed->greaterThan(CarbonImmutable::now()->addMinutes(5))) {
            throw new BusinessRuleException('The observation time cannot be in the future.', 'invalid_datetime', ['observed_at' => ['Future time.']], 422);
        }

        $dto = new AisPositionDTO(
            $vessel->id, ManualAisProvider::NAME, Decimal::round((string) $data['latitude'], 6), Decimal::round((string) $data['longitude'], 6), $observed,
            isset($data['sog_kn']) ? Decimal::round((string) $data['sog_kn'], 2) : null, isset($data['cog_deg']) ? Decimal::round((string) $data['cog_deg'], 2) : null,
            isset($data['heading_deg']) ? (int) $data['heading_deg'] : null, $data['nav_status'] ?? null, $data['destination'] ?? null, null, null,
            $vessel->imo_number, $vessel->mmsi, $actor->id,
        );

        $created = $this->store($dto);
        if ($created === null) {
            throw new BusinessRuleException('A manual position for this vessel and time already exists.', 'duplicate_position');
        }
        activity('ais')->performedOn($vessel)->causedBy($actor)->event('manual_position')->log("Manual AIS position recorded for {$vessel->name}");

        return $created;
    }

    /**
     * Stores positions idempotently (unique on vessel, observed_at, provider) and keeps
     * vessel_latest_positions pointing at the newest one.
     *
     * @param  list<AisPositionDTO>  $positions
     * @return int number of new positions
     */
    public function ingest(array $positions): int
    {
        $new = 0;
        foreach ($positions as $dto) {
            $new += $this->store($dto) !== null ? 1 : 0;
        }

        return $new;
    }

    /** @return list<VesselIdentifier> vessels worth polling: active vessels on an active voyage */
    public function monitoredVessels(): array
    {
        $ids = Voyage::query()->whereNotIn('status', self::INACTIVE_VOYAGE)->pluck('vessel_id')->unique();

        return Vessel::query()->whereIn('id', $ids)->where('status', 'active')->get(['id', 'mmsi', 'imo_number'])
            ->map(fn (Vessel $v) => new VesselIdentifier($v->id, $v->mmsi, $v->imo_number))->all();
    }

    public function provider(): AisProviderInterface
    {
        return $this->provider;
    }

    /**
     * Vessels on an active voyage whose latest position is missing or older than ais.stale_hours.
     * Also refreshes the is_stale flag on vessel_latest_positions.
     *
     * @return list<array{vessel: Vessel, voyage: Voyage, observed_at: CarbonImmutable|null}>
     */
    public function staleVessels(): array
    {
        $cutoff = CarbonImmutable::now()->subHours($this->staleHours());
        VesselLatestPosition::query()->where('observed_at', '<', $cutoff)->update(['is_stale' => true]);
        VesselLatestPosition::query()->where('observed_at', '>=', $cutoff)->update(['is_stale' => false]);

        $out = [];
        $latest = VesselLatestPosition::query()->pluck('observed_at', 'vessel_id');
        foreach (Voyage::query()->with('vessel:id,name,status')->whereNotIn('status', self::INACTIVE_VOYAGE)->get() as $voyage) {
            if ($voyage->vessel === null || $voyage->vessel->status !== 'active') {
                continue;
            }
            $seen = $latest->get($voyage->vessel_id);
            if ($seen === null || $seen->lessThan($cutoff)) {
                $out[] = ['vessel' => $voyage->vessel, 'voyage' => $voyage, 'observed_at' => $seen ? CarbonImmutable::instance($seen) : null];
            }
        }

        return $out;
    }

    private function store(AisPositionDTO $dto): ?AisPosition
    {
        return DB::transaction(function () use ($dto) {
            try {
                $position = AisPosition::query()->firstOrCreate(
                    ['vessel_id' => $dto->vesselId, 'observed_at' => $dto->observedAt, 'provider' => $dto->provider],
                    [
                        'imo' => $dto->imo, 'mmsi' => $dto->mmsi, 'latitude' => $dto->latitude, 'longitude' => $dto->longitude, 'sog_kn' => $dto->sogKn, 'cog_deg' => $dto->cogDeg,
                        'heading_deg' => $dto->headingDeg, 'nav_status' => $dto->navStatus, 'destination' => $dto->destination, 'eta_reported' => $dto->etaReported,
                        'draught_m' => $dto->draughtM, 'received_at' => now(), 'recorded_by' => $dto->recordedBy, 'raw' => $dto->raw,
                    ],
                );
            } catch (UniqueConstraintViolationException) {
                return null; // concurrent insert of the same observation
            }
            if (! $position->wasRecentlyCreated) {
                return null;
            }

            $latest = VesselLatestPosition::query()->where('vessel_id', $dto->vesselId)->lockForUpdate()->first();
            if ($latest === null || $dto->observedAt->greaterThan($latest->observed_at)) {
                VesselLatestPosition::query()->updateOrCreate(
                    ['vessel_id' => $dto->vesselId],
                    ['ais_position_id' => $position->id, 'observed_at' => $dto->observedAt, 'is_stale' => $dto->observedAt->lessThan(now()->subHours($this->staleHours()))],
                );
            }

            return $position;
        });
    }

    private function assertEnabled(): void
    {
        if (! $this->isEnabled()) {
            throw new BusinessRuleException('AIS is disabled. Enable it under Settings → AIS.', 'ais_disabled');
        }
    }

    private function authorize(User $actor, Permission $permission): void
    {
        if (! $actor->can($permission->value)) {
            throw new AuthorizationException('You do not have permission to perform this action.');
        }
    }
}
