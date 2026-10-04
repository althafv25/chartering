<?php

namespace App\Domain\Estimation\Strategy;

final class VoyageCharterStrategy extends StandardStrategy
{
    public function type(): string
    {
        return 'voyage_charter';
    }

    public function label(): string
    {
        return 'Voyage charter';
    }

    /** Owner pays bunkers and port costs; revenue is freight per MT. */
    public function defaults(): array
    {
        return ['bunker_account' => 'owner', 'port_cost_account' => 'owner', 'revenue_category' => 'FREIGHT', 'revenue_basis' => 'per_mt'];
    }
}
