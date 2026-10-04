<?php

namespace App\Domain\Estimation\Strategy;

final class TimeCharterStrategy extends StandardStrategy
{
    public function type(): string
    {
        return 'time_charter';
    }

    public function label(): string
    {
        return 'Time charter';
    }

    /** Charterer pays bunkers and port costs; revenue is hire per day over elapsed days. */
    public function defaults(): array
    {
        return ['bunker_account' => 'charterer', 'port_cost_account' => 'charterer', 'revenue_category' => 'HIRE', 'revenue_basis' => 'per_day'];
    }
}
