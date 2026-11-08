<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Wishlist;

/** Only the owner reads or changes their own Wishlist. */
class WishlistPolicy
{
    public function view(User $user, Wishlist $model): bool
    {
        return $user->id === $model->user_id;
    }

    public function update(User $user, Wishlist $model): bool
    {
        return $user->id === $model->user_id;
    }

    public function delete(User $user, Wishlist $model): bool
    {
        return $user->id === $model->user_id;
    }
}
