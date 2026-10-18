<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired once, after the checkout transaction has committed, the moment a
 * buyer's cart becomes a real Order (App\Services\CheckoutService::place()).
 *
 * Listeners: App\Listeners\NotifySellersOfNewOrder (one database
 * notification per seller). Dot.Brain Knowledge Pack publishing would be a
 * second listener; it does not exist yet.
 *
 * Implements ShouldDispatchAfterCommit so that if checkout is ever called
 * from inside a larger transaction, listeners still wait for the outermost
 * commit.
 */
class OrderPlaced implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Order $order) {}
}
