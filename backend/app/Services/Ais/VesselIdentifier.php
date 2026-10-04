<?php

namespace App\Services\Ais;

/** What a provider is asked about: MMSI when present, otherwise IMO (docs/09 §1). */
final readonly class VesselIdentifier
{
    public function __construct(public int $vesselId, public ?string $mmsi, public ?string $imo) {}

    public function value(): ?string
    {
        return $this->mmsi ?: $this->imo;
    }
}
