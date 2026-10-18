<?php

namespace App\Listeners;

use App\Events\OrderPlaced;
use App\Models\Store;
use App\Models\User;
use App\Notifications\NewOrderNotification;

/**
 * Tells each seller on a new order, once, that they have something to
 * fulfil. Database notifications only -- no mail, no HTTP, nothing that can
 * fail slowly or leave the platform. Runs synchronously after the order's
 * transaction has committed (OrderPlaced is dispatched after commit).
 *
 * Sellers are found from the seller_id / store_id snapshot on the order's
 * lines, so exactly one notification is sent per seller no matter how many
 * of their lines the order holds, and the buyer is never notified.
 *
 * Auto-discovered by Laravel from the handle() type-hint; do not also
 * register it by hand or it will fire twice.
 */
class NotifySellersOfNewOrder
{
    public function handle(OrderPlaced $event): void
    {
        $order = $event->order;

        $lines = $order->items()->whereNotNull('seller_id')->get()->groupBy('seller_id');

        foreach ($lines as $sellerId => $sellerLines) {
            $seller = User::find($sellerId);
            $store = Store::find($sellerLines->first()->store_id);

            if ($seller === null || $store === null) {
                continue;
            }

            $seller->notify(new NewOrderNotification($order, $store));
        }
    }
}
