<?php

namespace Database\Factories;

use App\Models\PromoCode;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PromoCode>
 */
class PromoCodeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'code' => Str::upper(Str::random(8)),
            'discount_type' => 'percent',
            'discount_value' => 10,
            'is_active' => true,
        ];
    }
}
