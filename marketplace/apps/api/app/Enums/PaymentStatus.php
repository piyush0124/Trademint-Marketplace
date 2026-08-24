<?php

namespace App\Enums;

/**
 * Lifecycle of a single payment attempt (Phase 8).
 *
 * Distinct from OrderPaymentStatus: an order has one financial *position*, while
 * it may have several attempts against it (a failed card, then a bank transfer).
 * Only a server-verified event moves an attempt to Paid — never a client.
 */
enum PaymentStatus: string
{
    /** Created, nothing attempted yet. */
    case Pending = 'pending';
    /** Handed to a provider (or awaiting an offline transfer). */
    case Initiated = 'initiated';
    /** Provider reports it in flight. */
    case Processing = 'processing';
    case Paid = 'paid';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case PartiallyRefunded = 'partially_refunded';
    case Refunded = 'refunded';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Initiated => 'Initiated',
            self::Processing => 'Processing',
            self::Paid => 'Paid',
            self::Failed => 'Failed',
            self::Cancelled => 'Cancelled',
            self::PartiallyRefunded => 'Partially refunded',
            self::Refunded => 'Refunded',
        };
    }

    /** Money has actually been received (possibly partly returned since). */
    public function isSettled(): bool
    {
        return in_array($this, [self::Paid, self::PartiallyRefunded, self::Refunded], true);
    }

    /** Still expected to complete — blocks a second concurrent attempt. */
    public function isInFlight(): bool
    {
        return in_array($this, [self::Pending, self::Initiated, self::Processing], true);
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Paid, self::Failed, self::Cancelled, self::Refunded], true);
    }

    /** Only a settled payment can be refunded. */
    public function isRefundable(): bool
    {
        return in_array($this, [self::Paid, self::PartiallyRefunded], true);
    }
}
