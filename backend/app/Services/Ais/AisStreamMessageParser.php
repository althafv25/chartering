<?php

namespace App\Services\Ais;

use Carbon\CarbonImmutable;

/**
 * Pure parser: aisstream.io JSON message → AisPositionDTO. No I/O, no database — kept
 * separate from StreamAisPositions so the mapping can be unit-tested without a live
 * WebSocket connection. $mmsiToVesselId resolves a reported MMSI back to our fleet; a
 * position for an MMSI we do not track (e.g. inside the bounding box but not monitored)
 * is correctly ignored (returns null), not stored under a fabricated vessel id.
 */
final class AisStreamMessageParser
{
    /**
     * @param  array<string, mixed>  $payload  decoded JSON from one WebSocket text frame
     * @param  array<string, int>  $mmsiToVesselId  normalized (no leading zeros) MMSI => vessel_id
     */
    public static function toPositionDto(array $payload, array $mmsiToVesselId): ?AisPositionDTO
    {
        if (($payload['MessageType'] ?? null) !== 'PositionReport') {
            return null;
        }
        $meta = $payload['MetaData'] ?? [];
        $report = $payload['Message']['PositionReport'] ?? [];
        $mmsi = isset($meta['MMSI']) ? (string) $meta['MMSI'] : null;
        $lat = $meta['Latitude'] ?? null;
        $lon = $meta['Longitude'] ?? null;
        if ($mmsi === null || $lat === null || $lon === null) {
            return null;
        }

        $vesselId = $mmsiToVesselId[self::normalizeMmsi($mmsi)] ?? null;
        if ($vesselId === null) {
            return null;
        }

        $timestamp = isset($meta['time_utc']) ? CarbonImmutable::parse($meta['time_utc']) : CarbonImmutable::now();

        return new AisPositionDTO(
            vesselId: $vesselId,
            provider: AisStreamProvider::NAME,
            latitude: number_format((float) $lat, 6, '.', ''),
            longitude: number_format((float) $lon, 6, '.', ''),
            observedAt: $timestamp,
            sogKn: isset($report['Sog']) ? number_format((float) $report['Sog'], 2, '.', '') : null,
            cogDeg: isset($report['Cog']) ? number_format((float) $report['Cog'], 2, '.', '') : null,
            headingDeg: isset($report['TrueHeading']) ? (int) $report['TrueHeading'] : null,
            mmsi: $mmsi,
            raw: $payload,
        );
    }

    public static function normalizeMmsi(string $mmsi): string
    {
        return ltrim($mmsi, '0');
    }

    /** @param  list<VesselIdentifier>  $vessels @return list<string> up to 200 nine-character MMSIs, per aisstream's subscription limit */
    public static function mmsiFilter(array $vessels): array
    {
        $mmsis = [];
        foreach ($vessels as $vessel) {
            if ($vessel->mmsi) {
                $mmsis[] = str_pad($vessel->mmsi, 9, '0', STR_PAD_LEFT);
            }
        }

        return array_slice(array_values(array_unique($mmsis)), 0, 200);
    }

    /**
     * @return array{0: array{0: float, 1: float}, 1: array{0: float, 1: float}} two opposite corners, required by aisstream even when filtering by MMSI
     */
    public static function boundingBox(string $config): array
    {
        $parts = array_map('trim', explode(',', $config));
        $nums = array_filter($parts, fn ($p) => $p !== '' && is_numeric($p));
        if (count($nums) !== 4) {
            return [[-90.0, -180.0], [90.0, 180.0]];
        }
        [$lat1, $lon1, $lat2, $lon2] = array_map('floatval', array_values($nums));

        return [[$lat1, $lon1], [$lat2, $lon2]];
    }
}
