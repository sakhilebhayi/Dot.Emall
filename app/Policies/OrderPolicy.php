<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Added (platform-loop pass, 2026-08-01) alongside the new buyer-facing
 * `/orders` and `/orders/{order}` routes. Before this pass there was no
 * route at all where a buyer could look up an order by id, so nothing
 * enforced that a customer can only see their own orders — this closes
 * that gap explicitly rather than leaving a new route unguarded.
 *
 * The existing seller-side dashboard query in routes/web.php is unaffected
 * and was already correctly scoped (orders containing the current user's
 * own store's products).
 */
class OrderPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * A buyer may only view their own order.
     */
    public function view(User $user, Order $order): bool
    {
        return $user->id === $order->user_id;
    }
}
