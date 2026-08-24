<?php

namespace App\Events;

use App\Models\Enquiry;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A business agreed to engage with a requirement.
 *
 * Phase 5 ships the events but no listeners: there is no notification
 * infrastructure in the app yet, and building one is out of scope. These are
 * the extension points a notification/mail layer should subscribe to later,
 * so the workflow never has to be reopened to add them.
 */
class EnquiryAccepted
{
    use Dispatchable;

    public function __construct(public readonly Enquiry $enquiry) {}
}
