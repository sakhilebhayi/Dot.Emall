<?php

namespace App\Notifications;

use App\Models\Order;
use App\Services\Paystack\PaystackConfig;
use Illuminate\Notifications\Notification;

/**
 * Database-channel notice to a buyer whose order was cancelled because it
 * was not paid within the window (App\Console\Commands\ExpireUnpaidOrders).
 */
class UnpaidOrderCancelledNotification extends Notification
{
    public function __construct(public Order $order) {}

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
        $minutes = app(PaystackConfig::class)->unpaidWindowMinutes();

        return [
            'type' => 'order_unpaid_cancelled',
            'title' => 'Order cancelled: not paid',
            'message' => "Your order {$this->order->reference()} was not paid within {$minutes} minutes, so it was cancelled and the items went back into stock. If you did pay in the meantime, contact us and we will refund or reinstate it. You can place the order again from your cart.",
            'order_id' => $this->order->id,
            'url' => route('orders.show', $this->order->id),
        ];
    }
}
