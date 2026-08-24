<?php

namespace App\Enums;

/**
 * Lifecycle of a negotiation over a quote (Phase 6).
 *
 *   open ──counter──> countered ──counter──> countered ...
 *                          │
 *                          ├── accepted  (the party being offered to agrees)
 *                          ├── rejected  (the party being offered to declines)
 *                          ├── withdrawn (the offering party pulls out)
 *                          ├── expired   (the live offer's validity lapsed)
 *                          └── closed    (concluded administratively)
 *
 * Deliberately separate from QuoteStatus: the quote records *what* is on the
 * table, the negotiation records *how the conversation is going*. Keeping them
 * apart is what makes "accepted → counter offer" impossible to express.
 */
enum NegotiationStatus: string
{
    case Open = 'open';
    case Countered = 'countered';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Expired = 'expired';
    case Withdrawn = 'withdrawn';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Countered => 'Countered',
            self::Accepted => 'Accepted',
            self::Rejected => 'Rejected',
            self::Expired => 'Expired',
            self::Withdrawn => 'Withdrawn',
            self::Closed => 'Closed',
        };
    }

    /** Still negotiable: a counter, acceptance or rejection is possible. */
    public function isActive(): bool
    {
        return in_array($this, [self::Open, self::Countered], true);
    }

    /** Settled for good. No further commercial action is permitted. */
    public function isFinal(): bool
    {
        return ! $this->isActive();
    }
}
