<?php

namespace App\Enums;

enum PayableStatus: string
{
    case Draft = 'draft';
    case Approved = 'approved';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Approved => 'Approved',
            self::PartiallyPaid => 'Partially Paid',
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
        return $this === self::Draft;
    }

    public function canReceivePayment(): bool
    {
        return match ($this) {
            self::Approved, self::PartiallyPaid => true,
            default => false,
        };
    }
}
