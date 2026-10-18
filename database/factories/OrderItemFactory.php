<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderItem>
 */
class OrderItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'product_id' => null,
            'quantity' => 1,
            'unit_price' => 10,
            'total' => 10,
            'discount_amount' => 0,
            'status' => OrderStatus::Pending,
        ];
    }

    /**
     * Snapshots the product exactly the way checkout does, so a line built
     * in a test carries the same name, price, store and seller columns as
     * a real one.
     */
    public function forProduct(Product $product, int $quantity = 1): static
    {
        $product->loadMissing('store');

        return $this->state([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'store_id' => $product->store_id,
            'store_name' => $product->store->name,
            'seller_id' => $product->store->owner_id,
            'quantity' => $quantity,
            'unit_price' => $product->price,
            'total' => round((float) $product->price * $quantity, 2),
        ]);
    }

    public function status(OrderStatus $status): static
    {
        return $this->state(['status' => $status]);
    }
}
