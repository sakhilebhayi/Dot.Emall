<?php

namespace App\Listeners;

use App\Events\OrderPaid;
use App\Services\Notifications\OrderNotifier;

/**
 * Tells the buyer, once, that their payment was received (OrderPaid is dispatched after commit by PaymentService).
 * Auto-discovered from the handle() type-hint; do not also register it by hand or it will fire twice.
 */
class NotifyBuyerOfPayment
{
    public function __construct(private readonly OrderNotifier $notifier) {}

    public function handle(OrderPaid $event): void
    {
        $this->notifier->orderPaid($event->order);
    }
}
