<?php

namespace App\Policies;

use App\Models\Service;
use App\Models\User;

class ServicePolicy
{
    public function create(User $user): bool
    {
        return $user->isOwner() && $user->business !== null;
    }

    public function update(User $user, Service $service): bool
    {
        return $user->isAdmin() || $user->id === $service->business->user_id;
    }

    public function delete(User $user, Service $service): bool
    {
        return $this->update($user, $service);
    }
}
