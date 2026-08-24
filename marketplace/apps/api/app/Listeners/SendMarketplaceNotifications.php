<?php

namespace App\Listeners;

use App\Enums\NegotiationParty;
use App\Enums\NotificationType;
use App\Events\EnquiryAccepted;
use App\Events\EnquiryDeclined;
use App\Events\NegotiationAccepted;
use App\Events\NegotiationCountered;
use App\Events\NegotiationRejected;
use App\Events\NegotiationWithdrawn;
use App\Events\OrderCancelled;
use App\Events\OrderCompleted;
use App\Events\OrderPlaced;
use App\Events\OrderStatusChanged;
use App\Events\PaymentFailed;
use App\Events\PaymentSucceeded;
use App\Events\QuoteAccepted;
use App\Events\QuoteExpired;
use App\Events\QuoteRejected;
use App\Events\QuoteSent;
use App\Events\QuoteViewed;
use App\Events\QuoteWithdrawn;
use App\Events\RefundRecorded;
use App\Models\Enquiry;
use App\Models\Negotiation;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Quote;
use App\Models\User;
use App\Notifications\MarketplaceNotification;
use Illuminate\Events\Dispatcher;

/**
 * The one place that decides who gets told what.
 *
 * ---------------------------------------------------------------------------
 * WHY A SUBSCRIBER AND NOT NINETEEN LISTENERS
 * ---------------------------------------------------------------------------
 * "Which party is notified when a quote is withdrawn?" is a question about policy,
 * and it should be answerable by reading one file. Split across nineteen listener
 * classes, the routing rules are only discoverable by opening all of them, and the
 * asymmetries — some events notify one side, some notify both — stop being visible
 * as a set. That is how a notification layer drifts into telling the wrong person.
 *
 * ---------------------------------------------------------------------------
 * THE ROUTING RULE
 * ---------------------------------------------------------------------------
 * Notify the party who did *not* cause the event, and only when the event tells
 * them something they could not already know.
 *
 * That second clause is why QuoteSent notifies the customer but not the business:
 * the business just pressed send. Notifying an actor about their own action is how
 * an inbox becomes noise, and a noisy inbox is one people stop opening — which
 * costs more than never having built it.
 *
 * Terminal negotiation outcomes are the deliberate exception and notify both
 * sides. The event payload does not record who acted, and both parties genuinely
 * need to know a negotiation ended, so guessing would risk telling the wrong
 * person while telling both is merely slightly redundant for one of them.
 *
 * ---------------------------------------------------------------------------
 * FAILURE POSTURE
 * ---------------------------------------------------------------------------
 * Every handler is wrapped by `deliver()`, which swallows exceptions.
 *
 * These events fire inside domain transactions — QuoteAccepted, for instance, is
 * what creates the order. A notification failing must never roll back or 500 the
 * business operation that triggered it. An order that exists but whose "order
 * placed" notification was lost is a recoverable annoyance; a payment that failed
 * to record because a notification threw is not.
 */
class SendMarketplaceNotifications
{
    /**
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            EnquiryAccepted::class => 'onEnquiryAccepted',
            EnquiryDeclined::class => 'onEnquiryDeclined',

            QuoteSent::class => 'onQuoteSent',
            QuoteViewed::class => 'onQuoteViewed',
            QuoteAccepted::class => 'onQuoteAccepted',
            QuoteRejected::class => 'onQuoteRejected',
            QuoteWithdrawn::class => 'onQuoteWithdrawn',
            QuoteExpired::class => 'onQuoteExpired',

            NegotiationCountered::class => 'onNegotiationCountered',
            NegotiationAccepted::class => 'onNegotiationAccepted',
            NegotiationRejected::class => 'onNegotiationRejected',
            NegotiationWithdrawn::class => 'onNegotiationWithdrawn',

            OrderPlaced::class => 'onOrderPlaced',
            OrderStatusChanged::class => 'onOrderStatusChanged',
            OrderCompleted::class => 'onOrderCompleted',
            OrderCancelled::class => 'onOrderCancelled',

            PaymentSucceeded::class => 'onPaymentSucceeded',
            PaymentFailed::class => 'onPaymentFailed',
            RefundRecorded::class => 'onRefundRecorded',
        ];
    }

    // ---------------------------------------------------------------- enquiry

    public function onEnquiryAccepted(EnquiryAccepted $event): void
    {
        $enquiry = $event->enquiry->loadMissing(['business', 'requirement']);

        $this->deliver(
            $this->customerOf($enquiry),
            NotificationType::EnquiryAccepted,
            'Supplier accepted your enquiry',
            sprintf(
                '%s accepted your enquiry on "%s". Expect a quote shortly.',
                $enquiry->business?->name ?? 'A supplier',
                $this->shorten($enquiry->requirement?->title ?? 'your requirement'),
            ),
            '/dashboard/enquiries/'.$enquiry->uuid,
            'enquiry',
            $enquiry->uuid,
        );
    }

    public function onEnquiryDeclined(EnquiryDeclined $event): void
    {
        $enquiry = $event->enquiry->loadMissing(['business', 'requirement']);

        $this->deliver(
            $this->customerOf($enquiry),
            NotificationType::EnquiryDeclined,
            'Supplier declined your enquiry',
            sprintf(
                '%s declined your enquiry on "%s". Other matched suppliers can still quote.',
                $enquiry->business?->name ?? 'A supplier',
                $this->shorten($enquiry->requirement?->title ?? 'your requirement'),
            ),
            '/dashboard/enquiries/'.$enquiry->uuid,
            'enquiry',
            $enquiry->uuid,
        );
    }

    // ------------------------------------------------------------------ quote

    /**
     * The single most valuable notification in the product.
     *
     * This is the moment a buyer has been waiting for since they posted a
     * requirement, and until now nothing told them it had happened.
     */
    public function onQuoteSent(QuoteSent $event): void
    {
        $quote = $event->quote->loadMissing(['business', 'requirement']);

        $this->deliver(
            $this->customerOf($quote),
            NotificationType::QuoteReceived,
            'New quote received',
            sprintf(
                '%s sent you a quote for "%s"%s.',
                $quote->business?->name ?? 'A supplier',
                $this->shorten($quote->requirement?->title ?? 'your requirement'),
                $this->amountClause($quote->total_amount, $quote->currency, ' totalling '),
            ),
            '/dashboard/quotes/'.$quote->uuid,
            'quote',
            $quote->uuid,
            ['amount' => (string) $quote->total_amount, 'currency' => $quote->currency],
        );
    }

    public function onQuoteViewed(QuoteViewed $event): void
    {
        $quote = $event->quote->loadMissing(['business', 'customer']);

        $this->deliver(
            $this->businessUserOf($quote),
            NotificationType::QuoteViewed,
            'Your quote was opened',
            sprintf('%s has opened your quote.', $quote->customer?->name ?? 'The buyer'),
            '/dashboard/quotes/'.$quote->uuid,
            'quote',
            $quote->uuid,
        );
    }

    public function onQuoteAccepted(QuoteAccepted $event): void
    {
        $quote = $event->quote->loadMissing(['business', 'customer']);

        $this->deliver(
            $this->businessUserOf($quote),
            NotificationType::QuoteAccepted,
            'Quote accepted',
            sprintf(
                '%s accepted your quote%s. An order has been created.',
                $quote->customer?->name ?? 'The buyer',
                $this->amountClause($quote->total_amount, $quote->currency, ' for '),
            ),
            '/dashboard/quotes/'.$quote->uuid,
            'quote',
            $quote->uuid,
        );
    }

    public function onQuoteRejected(QuoteRejected $event): void
    {
        $quote = $event->quote->loadMissing(['business', 'customer']);

        $this->deliver(
            $this->businessUserOf($quote),
            NotificationType::QuoteRejected,
            'Quote not taken forward',
            sprintf('%s rejected your quote.', $quote->customer?->name ?? 'The buyer'),
            '/dashboard/quotes/'.$quote->uuid,
            'quote',
            $quote->uuid,
        );
    }

    public function onQuoteWithdrawn(QuoteWithdrawn $event): void
    {
        $quote = $event->quote->loadMissing(['business']);

        $this->deliver(
            $this->customerOf($quote),
            NotificationType::QuoteWithdrawn,
            'A quote was withdrawn',
            sprintf('%s withdrew their quote.', $quote->business?->name ?? 'A supplier'),
            '/dashboard/quotes/'.$quote->uuid,
            'quote',
            $quote->uuid,
        );
    }

    /**
     * Expiry notifies both sides, and it is the one case where notifying the
     * "cause" is right: nobody caused it. The buyer has lost an option they may
     * have been considering, and the supplier has lost a deal they may want to
     * re-issue. Both are actionable.
     */
    public function onQuoteExpired(QuoteExpired $event): void
    {
        $quote = $event->quote->loadMissing(['business', 'requirement']);
        $title = $this->shorten($quote->requirement?->title ?? 'a requirement');

        $this->deliver(
            $this->customerOf($quote),
            NotificationType::QuoteExpired,
            'A quote has expired',
            sprintf('The quote from %s for "%s" has expired.', $quote->business?->name ?? 'a supplier', $title),
            '/dashboard/quotes/'.$quote->uuid,
            'quote',
            $quote->uuid,
        );

        $this->deliver(
            $this->businessUserOf($quote),
            NotificationType::QuoteExpired,
            'Your quote expired',
            sprintf('Your quote for "%s" expired before it was accepted.', $title),
            '/dashboard/quotes/'.$quote->uuid,
            'quote',
            $quote->uuid,
        );
    }

    // ------------------------------------------------------------ negotiation

    /**
     * Routed by `awaiting_party`, which is the negotiation's own record of whose
     * turn it is. Deriving the recipient from the state machine rather than
     * guessing keeps this correct if the counter-offer rules change.
     */
    public function onNegotiationCountered(NegotiationCountered $event): void
    {
        $negotiation = $event->negotiation->loadMissing(['business', 'customer', 'requirement']);

        $recipient = $negotiation->awaiting_party === NegotiationParty::Business
            ? $this->businessUserOf($negotiation)
            : $this->customerOf($negotiation);

        $from = $negotiation->awaiting_party === NegotiationParty::Business
            ? ($negotiation->customer?->name ?? 'The buyer')
            : ($negotiation->business?->name ?? 'The supplier');

        $this->deliver(
            $recipient,
            NotificationType::NegotiationCountered,
            'Counter-offer received',
            sprintf('%s sent a counter-offer. It is your turn to respond.', $from),
            '/dashboard/negotiations/'.$negotiation->uuid,
            'negotiation',
            $negotiation->uuid,
            ['round' => $negotiation->round],
        );
    }

    public function onNegotiationAccepted(NegotiationAccepted $event): void
    {
        $this->notifyBothOnNegotiation(
            $event->negotiation,
            NotificationType::NegotiationAccepted,
            'Negotiation agreed',
            'The counter-offer was accepted. The agreed terms are now on the quote.',
        );
    }

    public function onNegotiationRejected(NegotiationRejected $event): void
    {
        $this->notifyBothOnNegotiation(
            $event->negotiation,
            NotificationType::NegotiationRejected,
            'Negotiation rejected',
            'The negotiation was rejected and is now closed.',
        );
    }

    public function onNegotiationWithdrawn(NegotiationWithdrawn $event): void
    {
        $this->notifyBothOnNegotiation(
            $event->negotiation,
            NotificationType::NegotiationWithdrawn,
            'Negotiation withdrawn',
            'The negotiation was withdrawn and is now closed.',
        );
    }

    // ------------------------------------------------------------------ order

    public function onOrderPlaced(OrderPlaced $event): void
    {
        $order = $event->order->loadMissing(['business', 'customer']);

        $this->deliver(
            $this->businessUserOf($order),
            NotificationType::OrderPlaced,
            'New order received',
            sprintf(
                'Order %s from %s%s. Confirm it to begin fulfilment.',
                $order->order_number,
                $order->customer?->name ?? 'a buyer',
                $this->amountClause($order->total_amount, $order->currency, ' for '),
            ),
            '/dashboard/orders/'.$order->uuid,
            'order',
            $order->uuid,
            ['order_number' => $order->order_number],
        );
    }

    /**
     * Fulfilment progress is the buyer's question, so only the buyer is told.
     *
     * The business changed the status itself, and telling someone what they just
     * did is the fastest way to make an inbox worthless.
     */
    public function onOrderStatusChanged(OrderStatusChanged $event): void
    {
        $order = $event->order->loadMissing(['business']);

        $this->deliver(
            $this->customerOf($order),
            NotificationType::OrderStatusChanged,
            'Order updated',
            sprintf(
                'Order %s is now %s.',
                $order->order_number,
                str_replace('_', ' ', $order->status?->value ?? 'updated'),
            ),
            '/dashboard/orders/'.$order->uuid,
            'order',
            $order->uuid,
            ['status' => $order->status?->value, 'order_number' => $order->order_number],
        );
    }

    public function onOrderCompleted(OrderCompleted $event): void
    {
        $order = $event->order->loadMissing(['business']);

        $this->deliver(
            $this->customerOf($order),
            NotificationType::OrderCompleted,
            'Order completed — leave a review',
            sprintf(
                'Order %s with %s is complete. You can now review this supplier.',
                $order->order_number,
                $order->business?->name ?? 'the supplier',
            ),
            '/dashboard/orders/'.$order->uuid,
            'order',
            $order->uuid,
        );
    }

    public function onOrderCancelled(OrderCancelled $event): void
    {
        $order = $event->order->loadMissing(['business', 'customer']);
        $body = sprintf('Order %s has been cancelled.', $order->order_number);

        // Either side can cancel, and the event does not record which — so both are
        // told rather than risking silence for the party who did not act.
        $this->deliver($this->customerOf($order), NotificationType::OrderCancelled, 'Order cancelled', $body, '/dashboard/orders/'.$order->uuid, 'order', $order->uuid);
        $this->deliver($this->businessUserOf($order), NotificationType::OrderCancelled, 'Order cancelled', $body, '/dashboard/orders/'.$order->uuid, 'order', $order->uuid);
    }

    // ------------------------------------------------------------------ money

    public function onPaymentSucceeded(PaymentSucceeded $event): void
    {
        $payment = $event->payment->loadMissing(['order.business', 'order.customer']);
        $order = $payment->order;

        if ($order === null) {
            return;
        }

        $this->deliver(
            $this->businessUserOf($order),
            NotificationType::PaymentSucceeded,
            'Payment received',
            sprintf(
                '%s received against order %s.',
                $this->money($payment->amount, $payment->currency),
                $order->order_number,
            ),
            '/dashboard/payments',
            'payment',
            $payment->uuid ?? (string) $payment->id,
            ['amount' => (string) $payment->amount, 'currency' => $payment->currency],
        );
    }

    public function onPaymentFailed(PaymentFailed $event): void
    {
        $payment = $event->payment->loadMissing(['order.customer']);
        $order = $payment->order;

        if ($order === null) {
            return;
        }

        $this->deliver(
            $this->customerOf($order),
            NotificationType::PaymentFailed,
            'Payment did not go through',
            sprintf(
                'Your %s payment for order %s failed. The order is still payable.',
                $this->money($payment->amount, $payment->currency),
                $order->order_number,
            ),
            '/dashboard/orders/'.$order->uuid,
            'payment',
            $payment->uuid ?? (string) $payment->id,
        );
    }

    public function onRefundRecorded(RefundRecorded $event): void
    {
        $refund = $event->refund->loadMissing(['payment.order.customer']);
        $order = $refund->payment?->order;

        if ($order === null) {
            return;
        }

        $this->deliver(
            $this->customerOf($order),
            NotificationType::RefundRecorded,
            'Refund issued',
            sprintf(
                'A refund of %s was recorded against order %s.',
                $this->money($refund->amount, $refund->currency),
                $order->order_number,
            ),
            '/dashboard/payments',
            'refund',
            (string) $refund->id,
            ['amount' => (string) $refund->amount, 'currency' => $refund->currency],
        );
    }

    // ------------------------------------------------------------- internals

    private function notifyBothOnNegotiation(
        Negotiation $negotiation,
        NotificationType $type,
        string $title,
        string $body,
    ): void {
        $negotiation->loadMissing(['business', 'customer']);
        $url = '/dashboard/negotiations/'.$negotiation->uuid;

        $this->deliver($this->customerOf($negotiation), $type, $title, $body, $url, 'negotiation', $negotiation->uuid);
        $this->deliver($this->businessUserOf($negotiation), $type, $title, $body, $url, 'negotiation', $negotiation->uuid);
    }

    /**
     * @param  Enquiry|Quote|Order|Negotiation  $record
     */
    private function customerOf(object $record): ?User
    {
        return $record->customer_id !== null
            ? User::query()->find($record->customer_id)
            : null;
    }

    /**
     * The owner behind a business, which is who actually has an inbox.
     *
     * A business is not notifiable — it has no email and no notifications
     * relationship. Every business-facing notification is delivered to its owning
     * user, and a business with no owner (which the schema permits transiently
     * during seeding) simply receives nothing rather than throwing.
     */
    private function businessUserOf(object $record): ?User
    {
        $record->loadMissing('business');

        $ownerId = $record->business?->user_id;

        return $ownerId !== null ? User::query()->find($ownerId) : null;
    }

    /**
     * Send, or quietly do nothing.
     *
     * The null check is not defensive padding: several of these records have
     * nullable owners in the schema, and a missing recipient is a normal state
     * rather than an error worth surfacing.
     */
    private function deliver(
        ?User $recipient,
        NotificationType $type,
        string $title,
        string $body,
        ?string $url = null,
        ?string $entityType = null,
        ?string $entityId = null,
        array $meta = [],
    ): void {
        if ($recipient === null) {
            return;
        }

        try {
            $recipient->notify(new MarketplaceNotification(
                type: $type,
                title: $title,
                body: $body,
                url: $url,
                entityType: $entityType,
                entityId: $entityId,
                meta: $meta,
            ));
        } catch (\Throwable $e) {
            /*
             * Swallowed deliberately. These events fire inside domain transactions —
             * QuoteAccepted is what creates an order — and a failed notification must
             * never roll back or 500 the business operation that caused it.
             *
             * Reported so the failure is visible in logs rather than truly silent.
             */
            report($e);
        }
    }

    /** Keeps a long requirement title from swamping a one-line notification body. */
    private function shorten(string $text, int $limit = 60): string
    {
        return mb_strlen($text) > $limit ? mb_substr($text, 0, $limit - 1).'…' : $text;
    }

    /**
     * An optional " for ₹12,500.00" clause, omitted when there is no real figure.
     *
     * A null check alone is not enough: `total_amount` is a decimal cast, so an
     * unpriced quote arrives here as 0 rather than null and produced the sentence
     * "sent you a quote totalling ₹0.00" in live testing. A zero total means the
     * quote has not been priced yet, and stating it as a price is worse than saying
     * nothing — it reads as an offer of free work.
     */
    private function amountClause(mixed $amount, ?string $currency, string $prefix): string
    {
        if ($amount === null || (float) $amount <= 0.0) {
            return '';
        }

        return $prefix.$this->money($amount, $currency);
    }

    /**
     * Format money the way the rest of the product does.
     *
     * INR uses Indian digit grouping — ₹1,25,000.00, not ₹125,000.00. The frontend
     * already formats with `Intl.NumberFormat('en-IN')`, so using PHP's
     * `number_format` here would render the same amount two different ways depending
     * on whether the user read it in a notification or on the quote page. On a
     * product whose whole job is moving money between businesses, that is exactly
     * the kind of inconsistency that makes someone doubt the number.
     *
     * Grouped by hand rather than via `NumberFormatter`, because the intl extension
     * is not guaranteed to be present and a currency string silently changing shape
     * with the deployment environment is worse than a few lines of arithmetic.
     */
    private function money(mixed $amount, ?string $currency): string
    {
        $currency ??= 'INR';

        $symbol = match ($currency) {
            'INR' => '₹',
            'USD' => '$',
            'EUR' => '€',
            default => $currency.' ',
        };

        $value = number_format((float) $amount, 2, '.', '');
        [$whole, $decimals] = explode('.', $value);

        $negative = str_starts_with($whole, '-');
        $whole = ltrim($whole, '-');

        if ($currency === 'INR' && strlen($whole) > 3) {
            // Last three digits, then groups of two: 12500000 -> 1,25,00,000
            $last3 = substr($whole, -3);
            $rest = substr($whole, 0, -3);
            $rest = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest) ?? $rest;
            $grouped = $rest.','.$last3;
        } else {
            $grouped = number_format((float) $whole, 0, '.', ',');
        }

        return ($negative ? '-' : '').$symbol.$grouped.'.'.$decimals;
    }
}
