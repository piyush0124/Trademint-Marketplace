<?php

namespace App\Policies;

use App\Models\Negotiation;
use App\Models\User;

/**
 * Access rules for negotiations (Phase 6).
 *
 * Two layers, deliberately separated:
 *
 *  - This policy answers "are you a party to this negotiation at all?" — the
 *    IDOR guard. Ownership is derived from the negotiation's own customer_id and
 *    business.user_id, never from anything in the request.
 *  - NegotiationService answers "is it your turn, and is the offer still live?" —
 *    the state machine. Those are 422s, not 403s, because the caller is entitled
 *    to be here but the action is not currently valid.
 *
 * Admins may read for moderation and dispute handling, and may never act
 * commercially for either side.
 */
class NegotiationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isCustomer() || $user->isOwner() || $user->isAdmin();
    }

    public function view(User $user, Negotiation $negotiation): bool
    {
        return $user->isAdmin() || $negotiation->partyFor($user) !== null;
    }

    /**
     * Counter, accept, reject, withdraw and post messages. Restricted to the two
     * actual parties; an admin is explicitly excluded from acting.
     */
    public function participate(User $user, Negotiation $negotiation): bool
    {
        return $negotiation->partyFor($user) !== null;
    }
}
