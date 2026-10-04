<?php

namespace App\Enums;

enum CompanyRole: string
{
    case Owner = 'owner';
    case Charterer = 'charterer';
    case Broker = 'broker';
    case Customer = 'customer';
    case Agent = 'agent';
    case Supplier = 'supplier';
    case Shipyard = 'shipyard';
    case Surveyor = 'surveyor';
    case PortAuthority = 'port_authority';
    case Insurer = 'insurer';
    case Operator = 'operator';
    case Other = 'other';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
