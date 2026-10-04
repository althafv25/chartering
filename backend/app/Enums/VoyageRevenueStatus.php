<?php

namespace App\Enums;

enum VoyageRevenueStatus: string
{
    case Draft = 'draft';
    case Confirmed = 'confirmed';
    case Invoiced = 'invoiced';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Confirmed => 'Confirmed',
            self::Invoiced => 'Invoiced',
            self::Cancelled => 'Cancelled',
        };
    }

    public function canEdit(): bool
    {
        return $this === self::Draft;
    }

    public function canInvoice(): bool
    {
        return $this === self::Confirmed;
    }
}
