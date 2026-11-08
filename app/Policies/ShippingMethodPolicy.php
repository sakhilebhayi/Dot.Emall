<?php

namespace App\Policies;

use App\Models\ShippingMethod;
use App\Models\Store;
use App\Models\User;

/** A store's owner manages that store's shipping methods and nobody else's. */
class ShippingMethodPolicy
{
    public function create(User $user, Store $store): bool
    {
        return $store->owner_id === $user->id;
    }

    public function update(User $user, ShippingMethod $method): bool
    {
        return $method->store?->owner_id === $user->id;
    }

    public function delete(User $user, ShippingMethod $method): bool
    {
        return $this->update($user, $method);
    }
}
