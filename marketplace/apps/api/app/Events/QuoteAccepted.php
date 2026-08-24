<?php

namespace App\Events;

use App\Models\Quote;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The customer accepted a quote.
 *
 * This is the designated hand-off point for Phase 7: an order listener will
 * subscribe here to convert the accepted quote into an Order. Phase 5
 * deliberately creates no order — it only records the acceptance and fires
 * this event exactly once, inside the accepting transaction's commit path.
 */
class QuoteAccepted
{
    use Dispatchable;

    public function __construct(public readonly Quote $quote) {}
}
