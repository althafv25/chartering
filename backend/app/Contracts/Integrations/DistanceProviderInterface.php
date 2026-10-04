<?php

namespace App\Contracts\Integrations;

use App\Domain\Geo\DistanceResult;
use App\Domain\Geo\RoutePoint;

/**
 * Sea-distance provider (internal table, commercial API, ...). Implementations
 * must not throw for "no route"; return null instead. Transport/HTTP failures
 * throw App\Exceptions\IntegrationException.
 */
interface DistanceProviderInterface
{
    public function name(): string;

    public function distance(RoutePoint $from, RoutePoint $to, string $routeKey = ''): ?DistanceResult;
}
