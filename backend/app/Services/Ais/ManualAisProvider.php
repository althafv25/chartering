<?php

namespace App\Services\Ais;

use App\Models\AisPosition;
use Carbon\CarbonImmutable;

/** Positions are entered by users (ais.manual-position), so there is nothing to poll; history reads back the stored manual rows. */
class ManualAisProvider implements AisProviderInterface
{
    public const NAME = 'manual';

    public function name(): string
    {
        return self::NAME;
    }

    public function latestPositions(array $vessels): array
    {
        return [];
    }

    public function history(VesselIdentifier $vessel, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return AisPosition::query()->where('vessel_id', $vessel->vesselId)->where('provider', self::NAME)->whereBetween('observed_at', [$from, $to])
            ->orderBy('observed_at')->get()
            ->map(fn (AisPosition $p) => new AisPositionDTO($p->vessel_id, self::NAME, $p->latitude, $p->longitude, CarbonImmutable::instance($p->observed_at), $p->sog_kn, $p->cog_deg))
            ->all();
    }

    public function healthCheck(): ProviderHealth
    {
        return new ProviderHealth(true, 'Manual entry: no external provider.');
    }
}
