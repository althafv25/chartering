<?php

namespace App\Integrations\Distance;

use App\Contracts\Integrations\DistanceProviderInterface;
use App\Domain\Geo\DistanceResult;
use App\Domain\Geo\RoutePoint;
use App\Models\PortDistance;

/**
 * Internal distance table (manual entries + cached provider results).
 * Distances are treated as symmetric unless a route-specific row exists.
 * Manual rows win over provider rows; newest wins within a provider.
 */
class StoredDistanceProvider implements DistanceProviderInterface
{
    public function name(): string
    {
        return 'stored';
    }

    public function distance(RoutePoint $from, RoutePoint $to, string $routeKey = ''): ?DistanceResult
    {
        $find = fn (RoutePoint $a, RoutePoint $b) => PortDistance::query()
            ->where(['from_type' => $a->type, 'from_id' => $a->id, 'to_type' => $b->type, 'to_id' => $b->id, 'route_key' => $routeKey])
            ->orderByRaw("provider = 'manual' desc")->orderByDesc('calculated_at')->first();

        $row = $find($from, $to);
        $reversed = false;
        if (! $row) {
            $row = $find($to, $from);
            $reversed = (bool) $row;
        }

        if (! $row) {
            return null;
        }

        return new DistanceResult(
            $row->distance_nm, $row->eca_distance_nm, $row->provider,
            $row->calculated_at->toIso8601String(), false, $row->id, $reversed, $row->notes,
        );
    }
}
