<?php

namespace App\Listeners;

use App\Events\QuoteAccepted;
use App\Services\OrderService;

/**
 * Phase 7 plugging into the extension point Phase 5 left open.
 *
 * QuoteAccepted is dispatched from exactly one place per path — a straight
 * acceptance (QuoteService) or an accepted negotiation (NegotiationService) — and
 * OrderService::createFromQuote is idempotent, so a duplicate or replayed event
 * cannot produce a second order.
 *
 * Failures are reported rather than thrown: the acceptance itself is already
 * committed and must not be undone because order creation hiccupped. The order can
 * be created afterwards through POST /quotes/{quote}/order, which runs the same
 * idempotent code.
 */
class CreateOrderFromAcceptedQuote
{
    public function __construct(private readonly OrderService $orders) {}

    public function handle(QuoteAccepted $event): void
    {
        try {
            $this->orders->createFromQuote($event->quote);
        } catch (\Throwable $exception) {
            report($exception);
        }
    }
}
