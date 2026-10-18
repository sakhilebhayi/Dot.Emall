<?php

namespace App\Notifications;

use App\Models\OrderItem;
use Illuminate\Notifications\Notification;

/**
 * In-app (database channel) notification sent to the buyer when a seller
 * updates the fulfillment status of one of their order's line items.
 * Dispatched from SellerOrderController::updateItemStatus() — one
 * notification per status change, matching NewOrderNotification's
 * database-channel-only convention.
 */
class OrderItemStatusUpdatedNotification extends Notification
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
        $productName = $this->orderItem->displayName();
        $status = $this->orderItem->status->value;

        return [
            'type' => 'order_item_status_updated',
            'title' => 'Order update',
            'message' => "\"{$productName}\" from your order {$this->orderItem->order->reference()} is now {$status}.",
            'order_id' => $this->orderItem->order_id,
            'order_item_id' => $this->orderItem->id,
            'status' => $status,
            'url' => route('orders.show', $this->orderItem->order_id),
        ];
    }
}
