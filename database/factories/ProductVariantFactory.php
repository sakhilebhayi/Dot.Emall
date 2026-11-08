<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductVariant>
 */
class ProductVariantFactory extends Factory
{
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'price' => fake()->randomFloat(2, 5, 200),
            'stock' => 10,
            'is_active' => true,
            'is_default' => false,
            'options' => ['Size' => fake()->unique()->bothify('Size ??##')],
        ];
    }

    /** @param array<string, string> $options */
    public function options(array $options): static
    {
        return $this->state(['options' => $options]);
    }
}
