<?php

namespace App\Enums;

/**
 * Lifecycle of a requirement-level enquiry between one customer and one
 * business (Phase 5).
 *
 * Deliberately industry-neutral: the same five states describe an enquiry
 * about 500kg of steel, a website build, a construction project or a legal
 * consultation. Nothing here knows what is being bought.
 *
 * Distinct from LeadStatus, which is the CRM pipeline for a public enquiry
 * on a single listing — that flow is unrelated and stays as it is.
 */
enum EnquiryStatus: string
{
    /** Opened, awaiting the business's decision. */
    case Pending = 'pending';
    /** Business is willing to engage — quoting is now unlocked. */
    case Accepted = 'accepted';
    /** Business is not able to help. */
    case Declined = 'declined';
    /** Concluded (e.g. quote decided, or no longer being pursued). */
    case Closed = 'closed';
    /** Withdrawn by the customer before it went anywhere. */
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Accepted => 'Accepted',
            self::Declined => 'Declined',
            self::Closed => 'Closed',
            self::Cancelled => 'Cancelled',
        };
    }

    /** Terminal states: no further business action is meaningful. */
    public function isFinal(): bool
    {
        return in_array($this, [self::Declined, self::Closed, self::Cancelled], true);
    }
}
