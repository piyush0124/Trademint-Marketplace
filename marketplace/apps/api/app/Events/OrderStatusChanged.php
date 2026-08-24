<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;

/** An order moved to a new status. The immutable history row is already written. */
class OrderStatusChanged
{
    use Dispatchable;

    public function __construct(public readonly Order $order) {}
}
