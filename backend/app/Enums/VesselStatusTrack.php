<?php

namespace App\Enums;

/**
 * Two parallel status tracks (proposal for BR-VS-01 — requires confirmation):
 * commercial availability vs. physical/operational state.
 */
enum VesselStatusTrack: string
{
    case Commercial = 'commercial';
    case Operational = 'operational';

    /** @return list<string> */
    public function statuses(): array
    {
        return match ($this) {
            self::Commercial => ['available', 'open', 'on_hire', 'off_hire', 'under_charter', 'laid_up'],
            self::Operational => ['at_sea', 'at_port', 'mobilizing', 'demobilizing', 'offshore_operation', 'standby', 'maintenance', 'dry_dock'],
        };
    }

    public function column(): string
    {
        return $this->value.'_status';
    }
}
