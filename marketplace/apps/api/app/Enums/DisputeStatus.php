<?php

namespace App\Enums;

/** Dispute lifecycle (Phase 10 foundation). Admin-driven after it is raised. */
enum DisputeStatus: string
{
    case Open = 'open';
    case UnderReview = 'under_review';
    case Resolved = 'resolved';
    case Rejected = 'rejected';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::UnderReview => 'Under review',
            self::Resolved => 'Resolved',
            self::Rejected => 'Rejected',
            self::Closed => 'Closed',
        };
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Resolved, self::Rejected, self::Closed], true);
    }

    public function isActive(): bool
    {
        return ! $this->isFinal();
    }
}
