<?php

namespace App\Notifications;

use App\Models\Order;
use App\Models\Store;
use Illuminate\Notifications\Notification;

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
class NewOrderNotification extends Notification
{
    public function __construct(public Order $order, public Store $store) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

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
            'message' => "\"{$this->store->name}\" received a new order ({$this->order->reference()}): {$count} ".($count === 1 ? 'item' : 'items').' worth $'.number_format($netCents / 100, 2).' (unpaid).',
            'order_id' => $this->order->id,
            'store_id' => $this->store->id,
            'url' => route('orders.show', $this->order),
        ];
    }
}
