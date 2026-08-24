<?php

namespace App\Events;

use App\Models\Quote;
use Illuminate\Foundation\Events\Dispatchable;

/** The customer rejected a quote. The quote is kept for history, never deleted. */
class QuoteRejected
{
    use Dispatchable;

    public function __construct(public readonly Quote $quote) {}
}
