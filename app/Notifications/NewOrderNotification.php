<?php

namespace App\Notifications;

use App\Models\Order;
use App\Models\Store;
use Illuminate\Notifications\Notification;

/**
 * In-app (database channel) notification sent to a store owner the moment
 * a buyer places an order containing at least one of that store's
 * products. Dispatched from App\Http\Controllers\CartController::checkout()
 * — one notification per affected store, since a single order can span
 * multiple sellers.
 */
class NewOrderNotification extends Notification
{
    public function __construct(public Order $order, public Store $store)
    {
    }

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
            'type'     => 'new_order',
            'title'    => 'New order received',
            'message'  => "\"{$this->store->name}\" received a new order (#" . str_pad((string) $this->order->id, 5, '0', STR_PAD_LEFT) . ') worth $' . number_format((float) $this->order->total, 2) . '.',
            'order_id' => $this->order->id,
            'store_id' => $this->store->id,
            'url'      => route('dashboard'),
        ];
    }
}
