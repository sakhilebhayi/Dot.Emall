<?php

namespace App\Policies;

use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * An order can span several sellers, so every action on a line is checked
 * per line against the seller_id snapshotted at checkout -- which, unlike
 * product->store->owner, survives the product being archived or deleted.
 */
class OrderItemPolicy
{
    use HandlesAuthorization;

    /**
     * A seller moves their OWN lines through fulfilment.
     */
    public function updateStatus(User $user, OrderItem $orderItem): bool
    {
        return $orderItem->seller_id !== null && $user->id === $orderItem->seller_id;
    }

    /**
     * Either side of a line may cancel it: the buyer who placed the order,
     * or the seller who owns the line. Whether the line is still early
     * enough to cancel is a state rule, enforced by OrderLifecycle.
     */
    public function cancel(User $user, OrderItem $orderItem): bool
    {
        return $user->id === $orderItem->order->user_id
            || ($orderItem->seller_id !== null && $user->id === $orderItem->seller_id);
    }
}
