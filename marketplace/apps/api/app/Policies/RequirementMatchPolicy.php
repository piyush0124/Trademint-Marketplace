<?php

namespace App\Policies;

use App\Models\RequirementMatch;
use App\Models\User;

/**
 * Access rules for match records (Phase 4 security requirements).
 *
 * Two parties, two very different rights:
 *
 *  - The customer who raised the requirement can read every match on it, and
 *    nothing on anyone else's requirement. They cannot respond on a
 *    business's behalf.
 *  - A business can only ever touch a match that was created for *its own*
 *    business — being invited to one requirement grants no visibility into
 *    another, and it never sees who else was matched.
 *
 * Admins can read for support/moderation but are not given the ability to
 * answer on a business's behalf either.
 */
class RequirementMatchPolicy
{
    /**
     * The match *collection* endpoint is the business's matched-requirements
     * inbox, so it's Owner-only. A customer reads their matches through their
     * own requirement instead (RequirementPolicy::viewMatches), which keeps
     * each side on a route scoped to what it's allowed to see.
     */
    public function viewAny(User $user): bool
    {
        return $user->isOwner();
    }

    public function view(User $user, RequirementMatch $match): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $this->ownsRequirement($user, $match) || $this->ownsMatchedBusiness($user, $match);
    }

    /**
     * Accept / decline / ask a question. Restricted to the invited business:
     * the customer who raised the requirement must not be able to answer on
     * its behalf, and neither must an admin.
     */
    public function respond(User $user, RequirementMatch $match): bool
    {
        return $user->isOwner() && $this->ownsMatchedBusiness($user, $match);
    }

    /** Marking a match as viewed is meaningful only for the invited business. */
    public function markViewed(User $user, RequirementMatch $match): bool
    {
        return $this->ownsMatchedBusiness($user, $match);
    }

    private function ownsRequirement(User $user, RequirementMatch $match): bool
    {
        $match->loadMissing('requirement');

        return $match->requirement !== null && $match->requirement->customer_id === $user->id;
    }

    /**
     * A user "owns" a match when the invited business is their own. Compared
     * on the business's user_id rather than by loading the user's business
     * first, so a user without a business simply never matches.
     */
    private function ownsMatchedBusiness(User $user, RequirementMatch $match): bool
    {
        $match->loadMissing('business');

        return $match->business !== null && $match->business->user_id === $user->id;
    }
}
