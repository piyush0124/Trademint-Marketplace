<?php

namespace App\Events;

use App\Models\Negotiation;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A counter offer was made. A new immutable revision now holds the live terms.
 *
 * Phase 6 ships the events with no listeners: there is still no notification
 * infrastructure in the app, and these are the hooks a notification layer should
 * subscribe to later.
 */
class NegotiationCountered
{
    use Dispatchable;

    public function __construct(public readonly Negotiation $negotiation) {}
}
