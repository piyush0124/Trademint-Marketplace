<?php

namespace App\Enums;

/**
 * The catalogue of in-app notification kinds.
 *
 * ---------------------------------------------------------------------------
 * WHY AN ENUM RATHER THAN FREE STRINGS
 * ---------------------------------------------------------------------------
 * Every notification is written by one listener and read by one frontend
 * component, and both need to agree on the identifier. A typo in a free string
 * produces a notification that renders with no icon and no grouping, silently, in
 * production. An enum makes that a compile-time concern.
 *
 * It also gives one place to answer "how important is this", which the frontend
 * uses to decide styling, and which a future digest email would use to decide what
 * is worth interrupting someone for.
 */
enum NotificationType: string
{
    // --- Enquiry ---
    case EnquiryAccepted = 'enquiry.accepted';
    case EnquiryDeclined = 'enquiry.declined';

    // --- Quote ---
    case QuoteReceived = 'quote.received';
    case QuoteViewed = 'quote.viewed';
    case QuoteAccepted = 'quote.accepted';
    case QuoteRejected = 'quote.rejected';
    case QuoteWithdrawn = 'quote.withdrawn';
    case QuoteExpired = 'quote.expired';

    // --- Negotiation ---
    case NegotiationCountered = 'negotiation.countered';
    case NegotiationAccepted = 'negotiation.accepted';
    case NegotiationRejected = 'negotiation.rejected';
    case NegotiationWithdrawn = 'negotiation.withdrawn';

    // --- Order ---
    case OrderPlaced = 'order.placed';
    case OrderStatusChanged = 'order.status_changed';
    case OrderCompleted = 'order.completed';
    case OrderCancelled = 'order.cancelled';

    // --- Money ---
    case PaymentSucceeded = 'payment.succeeded';
    case PaymentFailed = 'payment.failed';
    case RefundRecorded = 'refund.recorded';

    /**
     * Importance, used by the client for emphasis and by any future digest to
     * decide what justifies an email.
     *
     * `action` means the recipient is now the blocker — someone is waiting on them.
     * `alert` means something went wrong and they need to know. `info` is a
     * progress update they will want but need not act on. The distinction exists
     * so that a UI can make a counter-offer awaiting reply look different from a
     * status change, which is the difference between a useful inbox and a list.
     */
    public function importance(): string
    {
        return match ($this) {
            self::QuoteReceived,
            self::NegotiationCountered,
            self::OrderPlaced,
            self::OrderCompleted => 'action',

            self::PaymentFailed,
            self::QuoteExpired,
            self::OrderCancelled,
            self::EnquiryDeclined,
            self::QuoteRejected,
            self::NegotiationRejected => 'alert',

            default => 'info',
        };
    }

    /** Icon name understood by the frontend Icon component. */
    public function icon(): string
    {
        return match ($this) {
            self::EnquiryAccepted, self::EnquiryDeclined => 'chat',
            self::QuoteReceived, self::QuoteViewed, self::QuoteAccepted,
            self::QuoteRejected, self::QuoteWithdrawn, self::QuoteExpired => 'receipt',
            self::NegotiationCountered, self::NegotiationAccepted,
            self::NegotiationRejected, self::NegotiationWithdrawn => 'handshake',
            self::OrderPlaced, self::OrderStatusChanged,
            self::OrderCompleted, self::OrderCancelled => 'package',
            self::PaymentSucceeded, self::PaymentFailed, self::RefundRecorded => 'card',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
