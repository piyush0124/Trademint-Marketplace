<?php

namespace App\Events;

use App\Models\Quote;
use Illuminate\Foundation\Events\Dispatchable;

/** The customer opened a sent quote for the first time. */
class QuoteViewed
{
    use Dispatchable;

    public function __construct(public readonly Quote $quote) {}
}
