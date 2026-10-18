<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * Approved and active by default: a listing a buyer can actually buy.
     * Use pending() / inactive() for the gated states.
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(3, true);

        return [
            'store_id' => Store::factory(),
            'name' => ucfirst($name),
            'slug' => Str::slug($name).'-'.Str::random(6),
            'description' => fake()->sentence(),
            'price' => fake()->randomFloat(2, 5, 200),
            'stock' => 10,
            'is_active' => true,
            'status' => 'approved',
        ];
    }

    public function pending(): static
    {
        return $this->state(['status' => 'pending_approval']);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
