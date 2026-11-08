<?php

namespace App\Services\Checkout;

use App\Enums\PaymentAttemptStatus;
use App\Models\Order;
use App\Services\Paystack\PaystackConfig;
use Illuminate\Support\Carbon;

/**
 * D-4 / D-22 in words a buyer can plan around: until when are the items of an unpaid order held. The rule is the one
 * orders:expire-unpaid enforces: the window from the order, longer while a payment attempt is open (measured from the attempt),
 * never more than the cap times the window from the order.
 */
class StockHold
{
    public function __construct(private readonly PaystackConfig $config) {}

    public function windowMinutes(): int
    {
        return $this->config->unpaidWindowMinutes();
    }

    /** The moment the hold ends for an order that is still waiting for money; null when nothing is being held. */
    public function until(Order $order): ?Carbon
    {
        if (! $order->isPayable()) {
            return null;
        }

        $window = $this->windowMinutes();
        $until = $order->created_at->copy()->addMinutes($window);
        $cap = $order->created_at->copy()->addMinutes($window * max(1, (int) config('emall.checkout.hold_extension_cap', 2)));

        $open = $order->payments()->where('status', PaymentAttemptStatus::Pending->value)->latest('created_at')->first();

        if ($open !== null) {
            $until = $open->created_at->copy()->addMinutes($window)->max($until);
        }

        return $until->min($cap);
    }
}
