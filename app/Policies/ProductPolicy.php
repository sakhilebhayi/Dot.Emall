<?php

namespace App\Policies;

use App\Models\Product;
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

    /**
     * Only the owning store's owner may edit or (de)activate a listing.
     */
    public function update(User $user, Product $product): bool
    {
        return $user->id === $product->store->owner_id;
    }

    /**
     * Only the owning store's owner may archive (soft-delete) a listing.
     */
    public function delete(User $user, Product $product): bool
    {
        return $user->id === $product->store->owner_id;
    }
}
