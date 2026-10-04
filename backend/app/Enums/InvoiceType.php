<?php

namespace App\Enums;

enum InvoiceType: string
{
    case FREIGHT = 'freight';
    case HIRE = 'hire';
    case OFFSHORE_SERVICE = 'offshore_service';
    case DEMURRAGE = 'demurrage';
    case OTHER = 'other';
    case CREDIT_NOTE = 'credit_note';

    public function label(): string
    {
        return match ($this) {
            self::FREIGHT => 'Freight',
            self::HIRE => 'Hire',
            self::OFFSHORE_SERVICE => 'Offshore Service',
            self::DEMURRAGE => 'Demurrage',
            self::OTHER => 'Other',
            self::CREDIT_NOTE => 'Credit Note',
        };
    }
}
