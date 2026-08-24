<?php

namespace App\Events;

use App\Models\Refund;
use Illuminate\Foundation\Events\Dispatchable;

/** A refund was recorded against a settled payment. */
class RefundRecorded
{
    use Dispatchable;

    public function __construct(public readonly Refund $refund) {}
}
