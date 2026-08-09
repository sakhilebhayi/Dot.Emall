<?php

namespace App\Policies;

use App\Models\Store;
use App\Models\User;

class ProductPolicy
{
    /**
     * Only a store's owner may create a listing for it.
     */
    public function create(User $user, Store $store): bool
    {
        return $user->id === $store->owner_id;
    }
}
