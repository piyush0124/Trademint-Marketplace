<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;

/**
 * Access rules for orders (Phase 7).
 *
 * Ownership is derived from the order's own customer_id and business.user_id.
 * Both parties can read their order and act on their own side of it; the specific
 * status each may set is decided by OrderService, because that is a question about
 * the workflow rather than about identity.
 *
 * Admins may read and, for dispute resolution, transition — that power is audited
 * in the order's immutable status history with `changed_by_party = admin`.
 */
class OrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isCustomer() || $user->isOwner() || $user->isAdmin();
    }

    public function view(User $user, Order $order): bool
    {
        return $user->isAdmin() || $this->partyFor($user, $order) !== null;
    }

    /** Progress the order. The permitted target statuses differ per party. */
    public function transition(User $user, Order $order): bool
    {
        return $this->partyFor($user, $order) !== null || $user->isAdmin();
    }

    /**
     * Start a payment (Phase 8). Restricted to the paying customer: a business
     * must not be able to "pay" its own order, and no third party can pay one at
     * all. Lives here rather than on PaymentPolicy because the authorised subject
     * is the order — that is what Laravel resolves the policy from.
     */
    public function pay(User $user, Order $order): bool
    {
        return $user->id === $order->customer_id;
    }

    /**
     * Review the business behind this order (Phase 9). Ownership only — whether the
     * order is *eligible* (completed, not already reviewed) is ReviewService's
     * decision, so the caller gets a 422 explaining why rather than a blank 403.
     */
    public function review(User $user, Order $order): bool
    {
        return $user->id === $order->customer_id;
    }

    /** Each side may annotate its own notes field. */
    public function annotate(User $user, Order $order): bool
    {
        return $this->partyFor($user, $order) !== null;
    }

    /**
     * Which side of the order this user is on: 'customer', 'business', or null.
     * Used by the controller to decide the acting party — never taken from input.
     */
    public function partyFor(User $user, Order $order): ?string
    {
        if ($user->id === $order->customer_id) {
            return 'customer';
        }

        $order->loadMissing('business');

        if ($order->business !== null && $order->business->user_id === $user->id) {
            return 'business';
        }

        return null;
    }
}
