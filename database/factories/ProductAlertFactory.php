<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductAlert;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProductAlert> */
class ProductAlertFactory extends Factory
{
    public function definition(): array
    {
        return ['user_id' => User::factory(), 'product_id' => Product::factory(), 'kind' => ProductAlert::BACK_IN_STOCK, 'variant_key' => 0, 'active' => true];
    }
}
