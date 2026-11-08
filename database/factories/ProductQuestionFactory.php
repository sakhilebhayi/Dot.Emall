<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductQuestion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProductQuestion> */
class ProductQuestionFactory extends Factory
{
    public function definition(): array
    {
        return ['product_id' => Product::factory(), 'user_id' => User::factory(), 'body' => fake()->sentence().'?'];
    }

    public function held(): static
    {
        return $this->state(['status' => 'held', 'held_reason' => 'link_or_contact_details']);
    }
}
