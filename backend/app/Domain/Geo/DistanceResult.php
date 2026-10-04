<?php

namespace App\Domain\Geo;

final class DistanceResult
{
    public function __construct(
        public readonly string $distanceNm,
        public readonly string $ecaDistanceNm,
        public readonly string $provider,
        public readonly string $calculatedAt,
        public readonly bool $isEstimate = false,
        public readonly ?int $cacheId = null,
        public readonly bool $reversed = false,
        public readonly ?string $notes = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'distance_nm' => $this->distanceNm,
            'eca_distance_nm' => $this->ecaDistanceNm,
            'provider' => $this->provider,
            'calculated_at' => $this->calculatedAt,
            'is_estimate' => $this->isEstimate,
            'cache_id' => $this->cacheId,
            'reversed' => $this->reversed,
            'notes' => $this->notes,
        ];
    }
}
