<?php

namespace App\Notifications;

use App\Models\OrderItem;

/**
 * Database-channel notification to a seller when a buyer cancels one of
 * their lines, so they stop preparing it.
 */
class OrderLineCancelledNotification extends EmallNotification
{
    public function __construct(public OrderItem $orderItem) {}

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'order_line_cancelled',
            'title' => 'Order line cancelled',
            'message' => "The buyer cancelled {$this->orderItem->quantity} × \"{$this->orderItem->displayName()}\" on order {$this->orderItem->order->reference()}. Do not ship it.",
            'order_id' => $this->orderItem->order_id,
            'order_item_id' => $this->orderItem->id,
            'url' => $this->orderItem->group ? route('seller.orders.show', $this->orderItem->group) : route('orders.show', $this->orderItem->order_id),
        ];
    }
}
