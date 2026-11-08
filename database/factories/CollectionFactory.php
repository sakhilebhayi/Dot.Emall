<?php

namespace Database\Factories;

use App\Models\Collection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Collection> */
class CollectionFactory extends Factory
{
    public function definition(): array
    {
        $title = ucfirst(fake()->unique()->words(3, true));

        return ['title' => $title, 'slug' => Str::slug($title).'-'.Str::random(5), 'type' => 'manual', 'visibility' => 'draft'];
    }

    public function live(): static
    {
        return $this->state(['visibility' => 'public']);
    }

    public function dynamic(array $rule): static
    {
        return $this->state(['type' => 'dynamic', 'rule' => $rule]);
    }
}
