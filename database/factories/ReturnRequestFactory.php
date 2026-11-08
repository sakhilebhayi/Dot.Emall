<?php

namespace Database\Factories;

use App\Enums\ReturnReason;
use App\Enums\ReturnStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ReturnRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReturnRequest>
 */
class ReturnRequestFactory extends Factory
{
    public function definition(): array
    {
        return [
            'order_item_id' => OrderItem::factory(),
            'order_id' => Order::factory(),
            'reason_category' => ReturnReason::Faulty,
            'status' => ReturnStatus::Requested,
            'refund_cents' => 1000,
            'respond_by' => now()->addDays(10),
        ];
    }
}
