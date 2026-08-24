<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;

class ProductPolicy
{
    public function create(User $user): bool
    {
        return $user->isOwner() && $user->business !== null;
    }

    public function update(User $user, Product $product): bool
    {
        return $user->isAdmin() || $user->id === $product->business->user_id;
    }

    public function delete(User $user, Product $product): bool
    {
        return $this->update($user, $product);
    }
}
