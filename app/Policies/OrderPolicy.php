<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;
use App\Services\OrderAccess;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Whether a user may open an order at all. What they then see is decided by
 * App\Services\OrderAccess::present() -- see its docblock for the rules:
 * buyers and operators see the whole order, a seller only their own lines.
 */
class OrderPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Order $order): bool
    {
        return app(OrderAccess::class)->roleFor($order, $user) !== null;
    }
}
