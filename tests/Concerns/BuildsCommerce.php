<?php

namespace Tests\Concerns;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ShippingMethod;
use App\Models\User;
use App\Services\OrderLifecycle;

/**
 * Fixtures for the R-43 commerce tests, on top of BuildsMarketplace and BuildsPayments: a seller with a delivery method,
 * and helpers that drive a line through the real state machine.
 */
trait BuildsCommerce
{
    protected function withDelivery(User $seller, array $attributes = []): ShippingMethod
    {
        return ShippingMethod::factory()->create(array_merge(['store_id' => $seller->store->id], $attributes));
    }

    /**
     * Moves every line of the order to the given status the way a seller would (pending, processing, shipped, delivered).
     */
    protected function advanceAll(Order $order, OrderStatus ...$path): Order
    {
        $lifecycle = app(OrderLifecycle::class);

        foreach ($path as $to) {
            foreach ($order->items()->get() as $line) {
                $lifecycle->advanceItem($line, $to, User::find($line->seller_id));
            }
        }

        return $order->fresh();
    }

    protected function deliver(Order $order): Order
    {
        return $this->advanceAll($order, OrderStatus::Shipped, OrderStatus::Delivered);
    }

    protected function lineOf(Order $order, int $n = 0): OrderItem
    {
        return $order->items()->orderBy('id')->get()[$n];
    }
}
