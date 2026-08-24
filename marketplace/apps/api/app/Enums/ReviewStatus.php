<?php

namespace App\Enums;

/**
 * Moderation state of a customer review (Phase 9).
 *
 * Published by default — a marketplace that holds every review for approval is a
 * marketing tool, not a trust signal. A review is only hidden by an *admin*, never
 * by the business it concerns, and hiding never deletes it.
 */
enum ReviewStatus: string
{
    case Published = 'published';
    case Hidden = 'hidden';
    /** Held for admin attention after being reported. */
    case Flagged = 'flagged';

    public function label(): string
    {
        return match ($this) {
            self::Published => 'Published',
            self::Hidden => 'Hidden',
            self::Flagged => 'Flagged for review',
        };
    }

    /** Counts towards the public rating. */
    public function isPublic(): bool
    {
        return in_array($this, [self::Published, self::Flagged], true);
    }
}
