<?php

namespace App\Policies;

use App\Enums\ListingStatus;
use App\Models\Listing;
use App\Models\User;

class ListingPolicy
{
    /** Used for the admin moderation queue listing (collection-level, not a single listing). */
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(?User $user, Listing $listing): bool
    {
        if ($listing->status === ListingStatus::Published) {
            return true;
        }

        if ($user === null) {
            return false;
        }

        return $user->id === $listing->user_id || $user->isAdmin();
    }

    /** Only Owner accounts may start a listing draft. Admins moderate, they don't list. */
    public function create(User $user): bool
    {
        return $user->isOwner();
    }

    /** Owner may edit only while the listing is still theirs to edit; admins can always edit. */
    public function update(User $user, Listing $listing): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $user->id === $listing->user_id && $listing->isEditableByOwner();
    }

    public function submit(User $user, Listing $listing): bool
    {
        return $user->id === $listing->user_id && $listing->isEditableByOwner();
    }

    public function moderate(User $user, Listing $listing): bool
    {
        return $user->isAdmin();
    }

    /** Owner or admin can archive at any workflow stage except already-archived. */
    public function archive(User $user, Listing $listing): bool
    {
        if ($listing->status === ListingStatus::Archived) {
            return false;
        }

        return $user->id === $listing->user_id || $user->isAdmin();
    }

    /** Only the owner can flip their own published listing's availability. */
    public function updateAvailability(User $user, Listing $listing): bool
    {
        return $user->id === $listing->user_id;
    }

    public function delete(User $user, Listing $listing): bool
    {
        return $this->archive($user, $listing);
    }
}
