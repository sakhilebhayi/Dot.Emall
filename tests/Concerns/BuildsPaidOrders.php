<?php

namespace Tests\Concerns;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;

/** Orders that count as real purchases (paid), and delivered lines, through the real CheckoutService. Needs BuildsMarketplace. */
trait BuildsPaidOrders
{
    /** @param  array<int, Product>  $products */
    protected function paidOrder(User $buyer, array $products, bool $delivered = false): Order
    {
        $order = $this->placeOrder($buyer, array_map(fn (Product $p) => [$p, 1], $products));

        $order->forceFill(['requires_payment' => true, 'payment_status' => PaymentStatus::Paid, 'paid_at' => now()])->save();

        if ($delivered) {
            $order->items()->update(['status' => OrderStatus::Delivered->value]);
            $order->forceFill(['status' => OrderStatus::Delivered])->save();
        }

        return $order->refresh();
    }
}
