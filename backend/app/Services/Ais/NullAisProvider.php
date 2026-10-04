<?php

namespace App\Services\Ais;

use Carbon\CarbonImmutable;

/** Default: no external feed. Every module keeps working from captain reports and manual entries. */
class NullAisProvider implements AisProviderInterface
{
    public function name(): string
    {
        return 'none';
    }

    public function latestPositions(array $vessels): array
    {
        return [];
    }

    public function history(VesselIdentifier $vessel, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return [];
    }

    public function healthCheck(): ProviderHealth
    {
        return new ProviderHealth(true, 'No AIS provider configured; positions are entered manually.');
    }
}
