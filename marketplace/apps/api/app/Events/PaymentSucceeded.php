<?php

namespace App\Events;

use App\Models\Payment;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A payment was settled server-side (verified webhook or authorised confirmation).
 *
 * The hook a receipt email or notification should subscribe to. No listeners ship
 * in this phase.
 */
class PaymentSucceeded
{
    use Dispatchable;

    public function __construct(public readonly Payment $payment) {}
}
