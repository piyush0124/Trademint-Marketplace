<?php

namespace App\Policies;

use App\Models\Review;
use App\Models\User;

/**
 * Access rules for reviews (Phase 9).
 *
 *  - Reading a published review needs no permission at all (it is public via the
 *    passport endpoints); this policy covers the authenticated, scoped views.
 *  - Only the reviewed business may respond, and only once.
 *  - Only an admin may moderate. A business cannot hide criticism of itself, and no
 *    one can delete a review — the model forbids deletion outright.
 */
class ReviewPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isCustomer() || $user->isOwner() || $user->isAdmin();
    }

    public function view(User $user, Review $review): bool
    {
        return $user->isAdmin()
            || $user->id === $review->customer_id
            || $this->isReviewedBusiness($user, $review);
    }

    public function respond(User $user, Review $review): bool
    {
        return $this->isReviewedBusiness($user, $review) && ! $review->hasResponse();
    }

    public function moderate(User $user): bool
    {
        return $user->isAdmin();
    }

    private function isReviewedBusiness(User $user, Review $review): bool
    {
        $review->loadMissing('business');

        return $review->business !== null && $review->business->user_id === $user->id;
    }
}
