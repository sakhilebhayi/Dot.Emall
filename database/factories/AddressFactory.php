<?php

namespace Database\Factories;

use App\Models\Address;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Address>
 */
class AddressFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'label' => 'Home',
            'recipient_name' => fake()->name(),
            'phone' => '+27 82 555 0100',
            'line1' => '14 Protea Lane',
            'line2' => null,
            'suburb' => 'Parkhurst',
            'city' => 'Johannesburg',
            'province' => 'Gauteng',
            'postal_code' => '2193',
            'country' => 'ZA',
            'instructions' => null,
            'is_default_shipping' => false,
            'is_default_billing' => false,
        ];
    }
}
