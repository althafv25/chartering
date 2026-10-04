<?php

namespace App\Enums;

enum VoyageExpenseStatus: string
{
    case Draft = 'draft';
    case Confirmed = 'confirmed';
    case Approved = 'approved';
    case Paid = 'paid';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Confirmed => 'Confirmed',
            self::Approved => 'Approved',
            self::Paid => 'Paid',
            self::Cancelled => 'Cancelled',
        };
    }

    public function canEdit(): bool
    {
        return $this === self::Draft;
    }

    public function canApprove(): bool
    {
        return $this === self::Confirmed;
    }
}
