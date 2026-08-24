<?php

namespace App\Events;

use App\Models\Quote;
use Illuminate\Foundation\Events\Dispatchable;

/** A quote's validity window closed without a decision. */
class QuoteExpired
{
    use Dispatchable;

    public function __construct(public readonly Quote $quote) {}
}
