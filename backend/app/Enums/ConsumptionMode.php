<?php

namespace App\Enums;

enum ConsumptionMode: string
{
    case SeaLaden = 'sea_laden';
    case SeaBallast = 'sea_ballast';
    case PortWorking = 'port_working';
    case PortIdle = 'port_idle';
    case Standby = 'standby';
    case DpOperation = 'dp_operation';
    case Manoeuvring = 'manoeuvring';

    /** Sea modes are speed-dependent; others use speed 0. */
    public function isSpeedDependent(): bool
    {
        return in_array($this, [self::SeaLaden, self::SeaBallast], true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
