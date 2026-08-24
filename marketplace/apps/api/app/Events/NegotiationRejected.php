<?php

namespace App\Events;

use App\Models\Negotiation;
use Illuminate\Foundation\Events\Dispatchable;

/** The party being offered to walked away. The revision history is preserved. */
class NegotiationRejected
{
    use Dispatchable;

    public function __construct(public readonly Negotiation $negotiation) {}
}
