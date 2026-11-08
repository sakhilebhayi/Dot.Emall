<?php

namespace Database\Factories;

use App\Enums\ShippingMethodType;
use App\Models\ShippingMethod;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShippingMethod>
 */
class ShippingMethodFactory extends Factory
{
    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'name' => 'Courier',
            'type' => ShippingMethodType::Flat,
            'price' => 60,
            'handling_days' => 1,
            'window_min_days' => 2,
            'window_max_days' => 4,
            'is_active' => true,
        ];
    }

    public function freeOver(float $threshold, float $price = 60): static
    {
        return $this->state(['type' => ShippingMethodType::FreeOver, 'price' => $price, 'free_over' => $threshold]);
    }

    public function collection(string $address = 'Unit 4, 22 Main Road, Cape Town'): static
    {
        return $this->state(['name' => 'Collect from us', 'type' => ShippingMethodType::Collection, 'price' => 0, 'collection_address' => $address]);
    }
}
