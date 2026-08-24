<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An order was created from an accepted quote.
 *
 * The hook a notification layer (and Phase 8's payment initiation prompt) should
 * subscribe to. No listeners ship in this phase.
 */
class OrderPlaced
{
    use Dispatchable;

    public function __construct(public readonly Order $order) {}
}
