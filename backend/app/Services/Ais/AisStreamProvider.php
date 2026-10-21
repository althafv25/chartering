<?php

namespace App\Services\Ais;

use App\Models\AisPosition;
use Carbon\CarbonImmutable;

/**
 * aisstream.io (wss://stream.aisstream.io/v0/stream) is a push WebSocket feed, not a
 * pollable endpoint — it does not fit the pull-based latestPositions() contract used by
 * the `ais:ingest` cron. The real work happens in the long-running `ais:stream` command
 * (App\Console\Commands\StreamAisPositions), which connects once, subscribes, and writes
 * every PositionReport straight into the database via AisService::ingest() as it arrives.
 * This class only satisfies the interface for naming/health-check consistency; history()
 * reads back whatever the streaming command already stored, exactly like ManualAisProvider.
 */
class AisStreamProvider implements AisProviderInterface
{
    public const NAME = 'aisstream';

    public function name(): string
    {
        return self::NAME;
    }

    /** Push-based: nothing to poll here. Positions arrive via the ais:stream command instead. */
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
        $configured = filled(config('offshore.ais.aisstream_api_key'));

        return $configured
            ? new ProviderHealth(true, 'aisstream.io configured. Run `php artisan ais:stream` as a long-running worker to receive live positions.')
            : new ProviderHealth(false, 'AISSTREAM_API_KEY is not set in .env.', 'Missing API key');
    }
}
