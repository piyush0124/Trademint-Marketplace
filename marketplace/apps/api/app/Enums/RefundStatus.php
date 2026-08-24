<?php

namespace App\Enums;

/** Lifecycle of a refund against a settled payment (Phase 8 foundation). */
enum RefundStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Refunded = 'refunded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Processing => 'Processing',
            self::Refunded => 'Refunded',
            self::Failed => 'Failed',
            self::Cancelled => 'Cancelled',
        };
    }

    /** Counts against the paid total. */
    public function isSettled(): bool
    {
        return $this === self::Refunded;
    }
}
