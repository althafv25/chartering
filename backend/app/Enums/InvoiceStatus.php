<?php

namespace App\Enums;

enum InvoiceStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Issued = 'issued';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Overdue = 'overdue';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Submitted => 'Submitted',
            self::Approved => 'Approved',
            self::Issued => 'Issued',
            self::PartiallyPaid => 'Partially Paid',
            self::Paid => 'Paid',
            self::Overdue => 'Overdue',
            self::Cancelled => 'Cancelled',
        };
    }

    public function canEdit(): bool
    {
        return match ($this) {
            self::Draft => true,
            default => false,
        };
    }

    public function canSubmit(): bool
    {
        return $this === self::Draft;
    }

    public function canApprove(): bool
    {
        return $this === self::Submitted;
    }

    public function canIssue(): bool
    {
        return $this === self::Approved;
    }

    public function canCancel(): bool
    {
        return match ($this) {
            self::Draft, self::Submitted, self::Approved => true,
            default => false,
        };
    }

    public function canReceivePayment(): bool
    {
        return match ($this) {
            self::Issued, self::PartiallyPaid, self::Overdue => true,
            default => false,
        };
    }
}
