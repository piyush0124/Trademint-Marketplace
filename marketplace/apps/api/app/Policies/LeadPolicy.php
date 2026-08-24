<?php

namespace App\Policies;

use App\Models\Lead;
use App\Models\User;

class LeadPolicy
{
    /** Used for the "my leads" collection endpoint — owner inbox only. */
    public function viewAny(User $user): bool
    {
        return $user->isOwner();
    }

    public function view(User $user, Lead $lead): bool
    {
        return $user->id === $lead->listing->user_id || $user->isAdmin();
    }

    public function update(User $user, Lead $lead): bool
    {
        return $user->id === $lead->listing->user_id || $user->isAdmin();
    }
}
