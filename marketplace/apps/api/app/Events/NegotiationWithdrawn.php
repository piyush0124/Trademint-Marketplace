<?php

namespace App\Events;

use App\Models\Negotiation;
use Illuminate\Foundation\Events\Dispatchable;

/** The offering party pulled its own live offer back before a response. */
class NegotiationWithdrawn
{
    use Dispatchable;

    public function __construct(public readonly Negotiation $negotiation) {}
}
