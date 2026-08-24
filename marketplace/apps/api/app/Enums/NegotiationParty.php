<?php

namespace App\Enums;

/**
 * Which side of a negotiation an actor is on.
 *
 * The whole Phase 6 turn system rests on this: a revision records the party
 * that authored it, and only the *other* party may respond to it. That single
 * rule prevents a business accepting its own offer, a customer accepting its
 * own counter, or either side countering itself into a better position.
 */
enum NegotiationParty: string
{
    case Customer = 'customer';
    case Business = 'business';

    public function label(): string
    {
        return match ($this) {
            self::Customer => 'Customer',
            self::Business => 'Business',
        };
    }

    /** The party whose turn it is to respond to an offer authored by this one. */
    public function counterparty(): self
    {
        return match ($this) {
            self::Customer => self::Business,
            self::Business => self::Customer,
        };
    }
}
