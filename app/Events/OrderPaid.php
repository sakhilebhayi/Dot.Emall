<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired once, after commit, when an order that required payment becomes
 * paid (App\Services\PaymentService): from then on it is something a seller
 * can fulfil. For orders that do not require payment the equivalent moment
 * is OrderPlaced. App\Listeners\NotifySellersOfNewOrder handles both.
 */
class OrderPaid implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Order $order) {}
}
