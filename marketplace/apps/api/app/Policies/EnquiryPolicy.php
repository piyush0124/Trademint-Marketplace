<?php

namespace App\Policies;

use App\Models\Enquiry;
use App\Models\User;

/**
 * Access rules for enquiries (Phase 5).
 *
 * Ownership is always derived from the record's own customer_id / business.user_id
 * against the authenticated user — never from anything the request carries. That
 * is what closes the IDOR hole: guessing another enquiry's UUID yields a 403,
 * because the check does not consult the URL for identity.
 *
 * Customer: reads and cancels its own enquiries. Cannot accept or decline —
 * that is the business's decision.
 * Business: reads and answers enquiries addressed to its own business only.
 * Admin: read-only for moderation and dispute handling; never allowed to act
 * commercially on either party's behalf.
 */
class EnquiryPolicy
{
    /** Both roles have a list endpoint; the controller scopes each to its own rows. */
    public function viewAny(User $user): bool
    {
        return $user->isCustomer() || $user->isOwner() || $user->isAdmin();
    }

    public function view(User $user, Enquiry $enquiry): bool
    {
        return $user->isAdmin()
            || $this->isCustomer($user, $enquiry)
            || $this->isBusiness($user, $enquiry);
    }

    /** Accept / decline: the invited business, and only while it is pending. */
    public function respond(User $user, Enquiry $enquiry): bool
    {
        return $user->isOwner() && $this->isBusiness($user, $enquiry) && $enquiry->awaitsBusinessResponse();
    }

    /** The customer may withdraw their own enquiry before it is accepted. */
    public function cancel(User $user, Enquiry $enquiry): bool
    {
        return $this->isCustomer($user, $enquiry);
    }

    /** Either party may conclude a conversation they are part of. */
    public function close(User $user, Enquiry $enquiry): bool
    {
        return $this->isCustomer($user, $enquiry) || $this->isBusiness($user, $enquiry);
    }

    /** Only the customer can add to their own side of the conversation. */
    public function message(User $user, Enquiry $enquiry): bool
    {
        return $this->isCustomer($user, $enquiry);
    }

    /**
     * Quoting is the business's right, gated on the enquiry being accepted.
     * Checked here as well as in QuoteService so an unauthorised attempt is a
     * 403 rather than a validation error.
     */
    public function createQuote(User $user, Enquiry $enquiry): bool
    {
        return $user->isOwner() && $this->isBusiness($user, $enquiry) && $enquiry->allowsQuoting();
    }

    private function isCustomer(User $user, Enquiry $enquiry): bool
    {
        return $user->id === $enquiry->customer_id;
    }

    /**
     * Compares against the business's owning user, so a user with no business
     * profile can never satisfy it, and Business B's owner can never match
     * Business A's enquiry.
     */
    private function isBusiness(User $user, Enquiry $enquiry): bool
    {
        $enquiry->loadMissing('business');

        return $enquiry->business !== null && $enquiry->business->user_id === $user->id;
    }
}
