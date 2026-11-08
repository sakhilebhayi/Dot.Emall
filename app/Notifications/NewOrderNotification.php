<?php

namespace App\Notifications;

use App\Models\Order;
use App\Models\Store;

/**
 * In-app (database channel) notification sent to a store owner when a buyer
 * places an order containing at least one of that store's products. One per
 * seller per order, sent by App\Listeners\NotifySellersOfNewOrder after the
 * order has committed.
 *
 * The message describes only THIS seller's share of the order (their line
 * count and their net value) -- never the order total, which would leak
 * what the buyer bought from other sellers.
 */
class NewOrderNotification extends EmallNotification
{
    public function __construct(public Order $order, public Store $store) {}

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $lines = $this->order->items()->where('store_id', $this->store->id)->get();
        $netCents = $lines->sum(fn ($line) => $line->netCents());
        $count = $lines->count();

        return [
            'type' => 'new_order',
            'title' => 'New order received',
            'message' => "\"{$this->store->name}\" received a new order ({$this->order->reference()}): {$count} ".($count === 1 ? 'item' : 'items').' worth '.$this->order->money($netCents).($this->order->requires_payment
                ? ' (the buyer has paid; payout to sellers is not available yet).'
                : ' (unpaid).'),
            'order_id' => $this->order->id,
            'store_id' => $this->store->id,
            // The seller's own parcel on the seller desk, not the buyer's order page.
            'url' => ($group = $this->order->groups()->where('store_id', $this->store->id)->first()) ? route('seller.orders.show', $group) : route('orders.show', $this->order),
        ];
    }
}
