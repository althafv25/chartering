<?php

namespace App\Domain\Estimation;

/**
 * Physical and monetary components shared by all estimation strategies.
 * All values are exact decimal strings in the scenario currency (unrounded).
 *
 * Cost buckets contain OWNER-account amounts only; charterer-account
 * amounts are reported separately in $chartererCosts and excluded from P&L.
 */
final class EngineComponents
{
    /**
     * @param  array<int, array{code:string, sea:string, port:string, dp:string, standby:string, total:string, cost:string, account:string}>  $fuel
     * @param  array<string, string>  $costs  fuel|port|agency|canal|tonnage|operational|other
     * @param  array<string, string>  $chartererCosts
     * @param  list<array<string, mixed>>  $revenueLines
     * @param  list<array<string, mixed>>  $costLines
     * @param  list<array<string, mixed>>  $legLines
     * @param  list<array<string, mixed>>  $callLines
     */
    public function __construct(
        public readonly string $seaDistanceNm,
        public readonly string $ecaDistanceNm,
        public readonly string $seaDays,
        public readonly string $ecaSeaDays,
        public readonly string $portDays,
        public readonly string $totalDays,
        public readonly array $fuel,
        public readonly string $fuelTotalMt,
        public readonly array $costs,
        public readonly array $chartererCosts,
        public readonly string $grossRevenue,
        public readonly string $commission,
        public readonly string $netRevenue,
        public readonly array $revenueLines,
        public readonly array $costLines,
        public readonly array $legLines,
        public readonly array $callLines,
    ) {}

    public function cost(string $bucket): string
    {
        return $this->costs[$bucket] ?? '0';
    }
}
