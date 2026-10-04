<?php

namespace App\Services\Ais;

use Carbon\CarbonImmutable;

interface AisProviderInterface
{
    public function name(): string;

    /**
     * @param  list<VesselIdentifier>  $vessels
     * @return list<AisPositionDTO>
     */
    public function latestPositions(array $vessels): array;

    /** @return list<AisPositionDTO> */
    public function history(VesselIdentifier $vessel, CarbonImmutable $from, CarbonImmutable $to): array;

    public function healthCheck(): ProviderHealth;
}
