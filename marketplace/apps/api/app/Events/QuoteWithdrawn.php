<?php

namespace App\Events;

use App\Models\Quote;
use Illuminate\Foundation\Events\Dispatchable;

/** The business pulled a sent quote back before the customer decided. */
class QuoteWithdrawn
{
    use Dispatchable;

    public function __construct(public readonly Quote $quote) {}
}
