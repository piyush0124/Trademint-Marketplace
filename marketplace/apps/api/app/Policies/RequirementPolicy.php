<?php

namespace App\Policies;

use App\Models\Requirement;
use App\Models\User;

class RequirementPolicy
{
    /** Used for the "my requirements" collection endpoint — customer dashboard only. */
    public function viewAny(User $user): bool
    {
        return $user->isCustomer();
    }

    /**
     * The owning customer and admins can always view. A business (Owner)
     * account may view a requirement only once it's publicly available —
     * never another customer's private draft. Everyone else is denied.
     */
    public function view(User $user, Requirement $requirement): bool
    {
        if ($user->id === $requirement->customer_id || $user->isAdmin()) {
            return true;
        }

        if ($user->isOwner()) {
            return in_array($requirement->status, Requirement::PUBLICLY_VISIBLE_STATUSES, true);
        }

        return false;
    }

    /**
     * The matched-businesses list is strictly for the customer who raised the
     * requirement (plus admins). Deliberately stricter than `view`: a business
     * may read a publicly-visible requirement, but letting it see the full
     * match list would expose which competitors were invited and how they
     * scored. A business only ever sees its own single match record.
     */
    public function viewMatches(User $user, Requirement $requirement): bool
    {
        return $user->id === $requirement->customer_id || $user->isAdmin();
    }

    /**
     * Re-running the engine is the customer's own action (a "refresh matches"
     * on their requirement), and only while the requirement is actually open
     * to businesses.
     */
    public function generateMatches(User $user, Requirement $requirement): bool
    {
        return $user->id === $requirement->customer_id && $requirement->isMatchable();
    }

    /** Only Customer accounts may raise a requirement — Owners list capabilities, they don't request them. */
    public function create(User $user): bool
    {
        return $user->isCustomer();
    }

    /** Owner (customer) may edit only while the requirement is still a draft. */
    public function update(User $user, Requirement $requirement): bool
    {
        return $user->id === $requirement->customer_id && $requirement->isEditableByCustomer();
    }

    public function publish(User $user, Requirement $requirement): bool
    {
        return $user->id === $requirement->customer_id && $requirement->isDraft();
    }

    /** Cancellable at any stage short of closed/cancelled, by its own customer only. */
    public function cancel(User $user, Requirement $requirement): bool
    {
        return $user->id === $requirement->customer_id && $requirement->isCancellable();
    }
}
