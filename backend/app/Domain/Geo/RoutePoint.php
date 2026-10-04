<?php

namespace App\Domain\Geo;

use InvalidArgumentException;

/** A port or an offshore location, with optional coordinates. */
final class RoutePoint
{
    public const TYPES = ['port', 'location'];

    public function __construct(
        public readonly string $type,
        public readonly int $id,
        public readonly string $label,
        public readonly ?string $latitude = null,
        public readonly ?string $longitude = null,
    ) {
        if (! in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException("Invalid route point type [{$type}].");
        }
    }

    public function key(): string
    {
        return "{$this->type}:{$this->id}";
    }

    public function hasCoordinates(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }
}
