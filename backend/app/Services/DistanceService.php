<?php

namespace App\Services;

use App\Contracts\Integrations\DistanceProviderInterface;
use App\Domain\Geo\DistanceResult;
use App\Domain\Geo\GreatCircle;
use App\Domain\Geo\RoutePoint;
use App\Exceptions\BusinessRuleException;
use App\Integrations\Distance\StoredDistanceProvider;
use App\Models\OffshoreLocation;
use App\Models\Port;
use App\Models\PortDistance;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Provider-independent distance lookup:
 *   1. stored table (manual / cached) — either direction
 *   2. external providers (none configured in phase 3; results get cached)
 *   3. great-circle ESTIMATE from coordinates — flagged, never cached
 */
class DistanceService
{
    /** @param list<DistanceProviderInterface> $externalProviders */
    public function __construct(
        private readonly StoredDistanceProvider $stored,
        private readonly array $externalProviders = [],
    ) {}

    public function point(string $type, int $id): RoutePoint
    {
        return match ($type) {
            'port' => (function () use ($id) {
                $p = Port::query()->findOrFail($id);

                return new RoutePoint('port', $p->id, $p->label(), $p->latitude, $p->longitude);
            })(),
            'location' => (function () use ($id) {
                $l = OffshoreLocation::query()->findOrFail($id);

                return new RoutePoint('location', $l->id, $l->name, $l->latitude, $l->longitude);
            })(),
            default => throw new BusinessRuleException("Invalid point type [{$type}].", 'invalid_point', status: 422),
        };
    }

    public function calculate(RoutePoint $from, RoutePoint $to, string $routeKey = ''): DistanceResult
    {
        if ($from->key() === $to->key()) {
            return new DistanceResult('0.00', '0.00', 'identity', now()->toIso8601String());
        }

        if ($hit = $this->stored->distance($from, $to, $routeKey)) {
            return $hit;
        }

        foreach ($this->externalProviders as $provider) {
            if ($result = $provider->distance($from, $to, $routeKey)) {
                $row = $this->store($from, $to, $routeKey, $result->distanceNm, $result->ecaDistanceNm, $provider->name(), null, null);

                return new DistanceResult($row->distance_nm, $row->eca_distance_nm, $row->provider, $row->calculated_at->toIso8601String(), false, $row->id);
            }
        }

        if ($from->hasCoordinates() && $to->hasCoordinates()) {
            $nm = GreatCircle::distanceNm((float) $from->latitude, (float) $from->longitude, (float) $to->latitude, (float) $to->longitude);

            return new DistanceResult(
                $nm, '0.00', 'great_circle_estimate', now()->toIso8601String(), true,
                notes: 'Straight-line estimate: ignores land, canals and ECA. Enter the actual sailing distance before relying on it.',
            );
        }

        throw new BusinessRuleException(
            "No distance is stored for {$from->label} → {$to->label}, and coordinates are missing for an estimate. Enter the distance manually.",
            'distance_unavailable',
        );
    }

    public function store(RoutePoint $from, RoutePoint $to, string $routeKey, string $nm, string $ecaNm, string $provider, ?string $notes, ?User $actor): PortDistance
    {
        if ($from->key() === $to->key()) {
            throw new BusinessRuleException('Origin and destination must be different.', 'same_point', status: 422);
        }

        return DB::transaction(fn () => PortDistance::query()->updateOrCreate(
            ['from_type' => $from->type, 'from_id' => $from->id, 'to_type' => $to->type, 'to_id' => $to->id, 'route_key' => $routeKey, 'provider' => $provider],
            ['distance_nm' => $nm, 'eca_distance_nm' => $ecaNm, 'notes' => $notes, 'calculated_at' => now(), 'created_by' => $actor?->id],
        ));
    }

    /** @param array<string, mixed> $f */
    public function paginate(array $f, int $perPage): LengthAwarePaginator
    {
        /** @var LengthAwarePaginator<int, PortDistance> */
        return PortDistance::query()->with('creator')
            ->when($f['point_type'] ?? null, function ($q) use ($f) {
                $q->where(fn ($w) => $w->where(['from_type' => $f['point_type'], 'from_id' => $f['point_id']])
                    ->orWhere(fn ($x) => $x->where(['to_type' => $f['point_type'], 'to_id' => $f['point_id']])));
            })
            ->when($f['provider'] ?? null, fn ($q, $p) => $q->where('provider', $p))
            ->latest('calculated_at')->paginate($perPage);
    }

    /**
     * Resolve labels for a page of distances in two queries (no N+1).
     *
     * @param  iterable<PortDistance>  $rows
     * @return array<string, string> "type:id" => label
     */
    public function labels(iterable $rows): array
    {
        $ids = ['port' => [], 'location' => []];
        foreach ($rows as $r) {
            $ids[$r->from_type][] = $r->from_id;
            $ids[$r->to_type][] = $r->to_id;
        }

        $labels = [];
        foreach (Port::withTrashed()->whereIn('id', array_unique($ids['port']))->get() as $p) {
            $labels["port:{$p->id}"] = $p->label();
        }
        foreach (OffshoreLocation::withTrashed()->whereIn('id', array_unique($ids['location']))->get() as $l) {
            $labels["location:{$l->id}"] = $l->name;
        }

        return $labels;
    }
}
