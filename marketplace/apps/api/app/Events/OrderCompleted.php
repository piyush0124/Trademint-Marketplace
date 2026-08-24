<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The customer signed off a completed order.
 *
 * Phase 9's review eligibility is derived from this state: only a completed order
 * earns the right to review.
 */
class OrderCompleted
{
    use Dispatchable;

    public function __construct(public readonly Order $order) {}
}
