<?php

namespace App\Enums;

/**
 * Where an order stands financially (Phase 7 stores it, Phase 8 drives it).
 *
 * Kept separate from the individual payment attempts: an order has one payment
 * *position*, while it may have many payment records against it. Only the payment
 * layer writes this — nothing in the fulfilment flow may declare an order paid.
 */
enum OrderPaymentStatus: string
{
    case Unpaid = 'unpaid';
    case Pending = 'pending';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case PartiallyRefunded = 'partially_refunded';
    case Refunded = 'refunded';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Unpaid => 'Unpaid',
            self::Pending => 'Payment pending',
            self::PartiallyPaid => 'Partially paid',
            self::Paid => 'Paid',
            self::PartiallyRefunded => 'Partially refunded',
            self::Refunded => 'Refunded',
            self::Failed => 'Payment failed',
        };
    }

    public function isSettled(): bool
    {
        return $this === self::Paid;
    }
}
