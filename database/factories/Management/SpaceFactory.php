<?php

namespace Database\Factories\Management;

use App\Models\Management\Space;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Space>
 */
class SpaceFactory extends Factory
{
    protected $model = Space::class;

    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'slug' => fake()->unique()->slug(2),
            'icon' => fake()->randomElement(['building', 'cube', 'globe', 'box']),
            'color' => fake()->hexColor(),
            'description' => fake()->sentence(),
            'settings' => [
                'timezone' => fake()->timezone(),
                'default_language' => 'en',
            ],
        ];
    }
    public function withLive(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'state' => 'live',
            ];
        });
    }
}
