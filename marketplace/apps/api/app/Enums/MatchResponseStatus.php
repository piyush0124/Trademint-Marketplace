<?php

namespace App\Enums;

/**
 * How a matched business has responded to a requirement invitation.
 *
 * Deliberately narrow: this tracks intent only ("yes I can help" / "no I
 * can't"), never commercial terms. Quotations and pricing are a later phase
 * and must not be inferred from an Accepted match.
 *
 * "Asked a question" is not a status — a business can ask something while
 * still undecided, so questions live in their own column and leave the
 * response Pending.
 */
enum MatchResponseStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Declined = 'declined';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Accepted => 'Accepted',
            self::Declined => 'Declined',
        };
    }

    /** A response is final — a business does not get to flip-flop on the customer. */
    public function isFinal(): bool
    {
        return $this !== self::Pending;
    }
}
