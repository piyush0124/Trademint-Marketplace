<?php

namespace App\Events;

use App\Models\Negotiation;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The party being offered to accepted the live revision.
 *
 * QuoteAccepted is dispatched alongside this, and remains the single hook Phase 7
 * order creation listens to — whether the quote was accepted straight away or
 * after a negotiation.
 */
class NegotiationAccepted
{
    use Dispatchable;

    public function __construct(public readonly Negotiation $negotiation) {}
}
