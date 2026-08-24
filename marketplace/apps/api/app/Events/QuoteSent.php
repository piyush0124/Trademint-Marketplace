<?php

namespace App\Events;

use App\Models\Quote;
use Illuminate\Foundation\Events\Dispatchable;

/** A business sent a quote to a customer. */
class QuoteSent
{
    use Dispatchable;

    public function __construct(public readonly Quote $quote) {}
}
