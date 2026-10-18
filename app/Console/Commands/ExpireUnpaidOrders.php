<?php

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Notifications\UnpaidOrderCancelledNotification;
use App\Services\OrderLifecycle;
use App\Services\PaymentService;
use App\Services\Paystack\PaystackConfig;
use Illuminate\Console\Command;

/**
 * Gives up orders that asked for payment and never got it, so their stock
 * and promo use are not held forever. Scheduled every ten minutes.
 *
 * An order qualifies when it requires payment, is not paid (unpaid, pending
 * or failed), is not already cancelled, and is older than
 * paystack.unpaid_window_minutes (default 60). Before cancelling, any
 * attempt still open at Paystack is checked, so a buyer who paid a moment
 * ago is not cancelled; and the cancel itself re-checks under the order lock
 * (OrderLifecycle::cancelUnpaidOrder). Orders placed without Paystack and
 * paid orders are never selected. Running it again does nothing new.
 */
class ExpireUnpaidOrders extends Command
{
    protected $signature = 'orders:expire-unpaid';

    protected $description = 'Cancel (and restock) orders that required payment and were not paid within the window';

    public function handle(OrderLifecycle $lifecycle, PaymentService $payments, PaystackConfig $config): int
    {
        $cutoff = now()->subMinutes($config->unpaidWindowMinutes());
        $cancelled = 0;

        Order::query()
            ->where('requires_payment', true)
            ->whereIn('payment_status', ['unpaid', 'pending', 'failed'])
            ->where('status', '!=', OrderStatus::Cancelled->value)
            ->where('created_at', '<=', $cutoff)
            ->orderBy('id')
            ->chunkById(100, function ($orders) use ($lifecycle, $payments, &$cancelled) {
                foreach ($orders as $order) {
                    $payments->verifyOpenAttempts($order);

                    if ($lifecycle->cancelUnpaidOrder($order)) {
                        $cancelled++;
                        $order->refresh()->user?->notify(new UnpaidOrderCancelledNotification($order));
                    }
                }
            });

        $this->info("Cancelled {$cancelled} unpaid ".($cancelled === 1 ? 'order' : 'orders').'.');

        return self::SUCCESS;
    }
}
