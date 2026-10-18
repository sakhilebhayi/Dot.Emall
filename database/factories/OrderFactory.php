<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    /**
     * An empty pending order; add lines with OrderItem::factory()->forOrder().
     * Totals are left at zero on purpose -- build lines first and call
     * OrderPricing::apply($order) (or use the tests' placeOrder helper) so
     * the totals come from the same code that checkout uses.
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'status' => OrderStatus::Pending,
            'subtotal' => 0,
            'discount_amount' => 0,
            'total' => 0,
            'shipping_name' => fake()->name(),
            'shipping_address' => fake()->streetAddress().', '.fake()->city(),
            'shipping_phone' => '+27 82 555 0100',
        ];
    }
}
