<?php

namespace App\Events;

use App\Models\Enquiry;
use Illuminate\Foundation\Events\Dispatchable;

/** A business declined a requirement. The customer should be told. */
class EnquiryDeclined
{
    use Dispatchable;

    public function __construct(public readonly Enquiry $enquiry) {}
}
