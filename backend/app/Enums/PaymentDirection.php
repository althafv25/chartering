<?php

namespace App\Enums;

enum PaymentDirection: string
{
    case RECEIVED = 'received';
    case PAID = 'paid';

    public function label(): string
    {
        return match ($this) {
            self::RECEIVED => 'Received',
            self::PAID => 'Paid',
        };
    }
}
