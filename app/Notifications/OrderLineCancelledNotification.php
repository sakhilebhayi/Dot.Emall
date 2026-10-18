<?php

namespace App\Notifications;

use App\Models\OrderItem;
use Illuminate\Notifications\Notification;

/**
 * Database-channel notification to a seller when a buyer cancels one of
 * their lines, so they stop preparing it.
 */
class OrderLineCancelledNotification extends Notification
{
    public function __construct(public OrderItem $orderItem) {}

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
        return [
            'type' => 'order_line_cancelled',
            'title' => 'Order line cancelled',
            'message' => "The buyer cancelled {$this->orderItem->quantity} × \"{$this->orderItem->displayName()}\" on order {$this->orderItem->order->reference()}. Do not ship it.",
            'order_id' => $this->orderItem->order_id,
            'order_item_id' => $this->orderItem->id,
            'url' => route('orders.show', $this->orderItem->order_id),
        ];
    }
}
