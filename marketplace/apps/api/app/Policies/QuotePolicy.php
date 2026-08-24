<?php

namespace App\Policies;

use App\Models\Quote;
use App\Models\User;

/**
 * Access rules for quotes (Phase 5).
 *
 * The asymmetry is the whole point:
 *
 *  - The issuing business writes the quote (create, edit draft, delete draft,
 *    send, withdraw) but can never accept or reject it on the customer's behalf.
 *  - The receiving customer reads it and decides (accept/reject) but can never
 *    modify its contents or its price.
 *  - An admin may read for moderation, and nothing more.
 *
 * A draft is not visible to the customer at all: an unsent quote is the
 * business's private working document.
 */
class QuotePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isCustomer() || $user->isOwner() || $user->isAdmin();
    }

    public function view(User $user, Quote $quote): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if ($this->isIssuingBusiness($user, $quote)) {
            return true;
        }

        // The customer sees a quote only once it has actually been sent.
        return $this->isReceivingCustomer($user, $quote) && ! $quote->isDraft();
    }

    /** Editing and deleting are restricted to the issuing business's own draft. */
    public function update(User $user, Quote $quote): bool
    {
        return $this->isIssuingBusiness($user, $quote) && $quote->status->isEditable();
    }

    public function delete(User $user, Quote $quote): bool
    {
        return $this->update($user, $quote);
    }

    public function send(User $user, Quote $quote): bool
    {
        return $this->isIssuingBusiness($user, $quote) && $quote->isDraft();
    }

    public function withdraw(User $user, Quote $quote): bool
    {
        return $this->isIssuingBusiness($user, $quote);
    }

    /**
     * Only the receiving customer decides. Expiry and status are re-checked in
     * QuoteService — this answers "may this person decide at all", not "is the
     * quote still decidable".
     */
    public function decide(User $user, Quote $quote): bool
    {
        return $this->isReceivingCustomer($user, $quote) && ! $quote->isDraft();
    }

    private function isReceivingCustomer(User $user, Quote $quote): bool
    {
        return $user->id === $quote->customer_id;
    }

    private function isIssuingBusiness(User $user, Quote $quote): bool
    {
        $quote->loadMissing('business');

        return $quote->business !== null && $quote->business->user_id === $user->id;
    }
}
