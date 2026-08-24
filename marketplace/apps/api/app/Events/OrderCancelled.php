<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;

/** An order was cancelled before work was committed. */
class OrderCancelled
{
    use Dispatchable;

    public function __construct(public readonly Order $order) {}
}
