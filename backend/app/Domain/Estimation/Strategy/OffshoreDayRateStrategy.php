<?php

namespace App\Domain\Estimation\Strategy;

final class OffshoreDayRateStrategy extends StandardStrategy
{
    public function type(): string
    {
        return 'offshore_day_rate';
    }

    public function label(): string
    {
        return 'Offshore day-rate';
    }

    /**
     * Owner pays bunkers unless the contract recharges fuel (BR-OA-04 open → user sets the account).
     * Revenue is the day rate over elapsed days; DP/standby days use dedicated consumption.
     */
    public function defaults(): array
    {
        return ['bunker_account' => 'owner', 'port_cost_account' => 'owner', 'revenue_category' => 'DAYRATE', 'revenue_basis' => 'per_day'];
    }
}
