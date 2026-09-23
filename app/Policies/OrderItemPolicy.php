<?php

namespace App\Policies;

use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * A seller may only update the fulfillment status of their OWN line items
 * -- an order can span multiple sellers' stores (see OrderItem's own doc
 * comment), so this is checked per item, not per order.
 */
class OrderItemPolicy
{
    use HandlesAuthorization;

    public function updateStatus(User $user, OrderItem $orderItem): bool
    {
        return $user->id === $orderItem->product->store->owner_id;
    }
}
