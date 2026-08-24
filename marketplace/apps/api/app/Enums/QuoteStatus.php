<?php

namespace App\Enums;

/**
 * Lifecycle of a quotation (Phase 5).
 *
 *   draft ──send──> sent ──customer opens──> viewed
 *                     │                        │
 *                     ├── negotiating (Phase 6: a counter offer was made)
 *                     ├── accepted / rejected (the party being offered to)
 *                     ├── withdrawn (the offering party, before acceptance)
 *                     └── expired (validity passed, enforced server-side)
 *
 * The blow-by-blow of a negotiation lives in NegotiationStatus and the
 * quote_revisions ledger; this enum only records what state the offer is in.
 */
enum QuoteStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';
    case Viewed = 'viewed';
    /** Phase 6: at least one counter offer has been made. Still decidable. */
    case Negotiating = 'negotiating';
    case Expired = 'expired';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Withdrawn = 'withdrawn';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Sent => 'Sent',
            self::Viewed => 'Viewed',
            self::Negotiating => 'Negotiating',
            self::Expired => 'Expired',
            self::Accepted => 'Accepted',
            self::Rejected => 'Rejected',
            self::Withdrawn => 'Withdrawn',
        };
    }

    /**
     * On the table and awaiting a decision. A quote under negotiation is still
     * live: the party being offered to may accept it at any point instead of
     * countering again.
     */
    public function isLive(): bool
    {
        return in_array($this, [self::Sent, self::Viewed, self::Negotiating], true);
    }

    /** Decided one way or another — nothing further happens to it. */
    public function isFinal(): bool
    {
        return in_array($this, [self::Accepted, self::Rejected, self::Withdrawn, self::Expired], true);
    }

    /**
     * Only a draft may be freely edited or deleted. Once a quote has been
     * sent it is a commercial offer the customer may be relying on, so its
     * pricing is frozen (see QuoteService).
     */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }
}
