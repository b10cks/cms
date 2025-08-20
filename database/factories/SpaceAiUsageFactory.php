<?php

namespace Database\Factories;

use App\Models\Management\Space;
use Illuminate\Database\Eloquent\Factories\Factory;

class SpaceAiUsageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'space_id' => Space::factory(),
            'max_tokens' => $this->faker->randomElement([10000, 50000, 100000, 500000, 1000000]),
            'used_tokens' => $this->faker->numberBetween(0, 50000),
            'valid_to' => $this->faker->dateTimeBetween('now', '+3 months'),
        ];
    }
}
