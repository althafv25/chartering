<?php

namespace App\Services\Ais;

use Carbon\CarbonImmutable;

/** Provider-neutral position. Coordinates and speeds stay decimal strings. */
final readonly class AisPositionDTO
{
    /** @param array<string, mixed>|null $raw */
    public function __construct(
        public int $vesselId,
        public string $provider,
        public string $latitude,
        public string $longitude,
        public CarbonImmutable $observedAt,
        public ?string $sogKn = null,
        public ?string $cogDeg = null,
        public ?int $headingDeg = null,
        public ?string $navStatus = null,
        public ?string $destination = null,
        public ?CarbonImmutable $etaReported = null,
        public ?string $draughtM = null,
        public ?string $imo = null,
        public ?string $mmsi = null,
        public ?int $recordedBy = null,
        public ?array $raw = null,
    ) {}
}
