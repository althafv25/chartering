<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case Recorded = 'recorded';
    case Reversed = 'reversed';

    public function label(): string
    {
        return match ($this) {
            self::Recorded => 'Recorded',
            self::Reversed => 'Reversed',
        };
    }

    public function canAllocate(): bool
    {
        return $this === self::Recorded;
    }
}
