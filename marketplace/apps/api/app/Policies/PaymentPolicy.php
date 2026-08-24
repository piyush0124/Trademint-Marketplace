<?php

namespace App\Policies;

use App\Models\Payment;
use App\Models\User;

/**
 * Access rules for payments (Phase 8).
 *
 * The asymmetry is the security model:
 *
 *  - Only the *paying customer* may start a payment on their own order — that
 *    ability is `pay` on OrderPolicy, since the order is the authorised subject.
 *  - Only the *receiving business* (or an admin) may confirm an offline payment or
 *    issue a refund. A customer confirming their own payment would be the whole
 *    fraud, so it is impossible from both here and PaymentService.
 *  - Both parties may read; an admin may read everything for reconciliation and
 *    dispute handling.
 */
class PaymentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isCustomer() || $user->isOwner() || $user->isAdmin();
    }

    public function view(User $user, Payment $payment): bool
    {
        return $user->isAdmin() || $this->partyFor($user, $payment) !== null;
    }

    /** Confirming receipt of an offline payment: the receiving side, or an admin. */
    public function confirm(User $user, Payment $payment): bool
    {
        return $user->isAdmin() || $this->partyFor($user, $payment) === 'business';
    }

    /** Refunds are issued by the business that received the money, or an admin. */
    public function refund(User $user, Payment $payment): bool
    {
        return $user->isAdmin() || $this->partyFor($user, $payment) === 'business';
    }

    public function partyFor(User $user, Payment $payment): ?string
    {
        if ($user->id === $payment->customer_id) {
            return 'customer';
        }

        $payment->loadMissing('business');

        if ($payment->business !== null && $payment->business->user_id === $user->id) {
            return 'business';
        }

        return null;
    }
}
