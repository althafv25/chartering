<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case WIRE = 'wire';
    case CHECK = 'check';
    case CREDIT_CARD = 'credit_card';
    case CASH = 'cash';
    case OTHER = 'other';

    public function label(): string
    {
        return match ($this) {
            self::WIRE => 'Wire Transfer',
            self::CHECK => 'Check',
            self::CREDIT_CARD => 'Credit Card',
            self::CASH => 'Cash',
            self::OTHER => 'Other',
        };
    }
}
