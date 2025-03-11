<?php

namespace Database\Factories\Management;

use App\Models\Management\Space;
use App\Models\Management\Token;
use App\ValueObjects\TokenAbility;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Token>
 */
class TokenFactory extends Factory
{
    protected $model = Token::class;

    public function definition(): array
    {
        return [
            'space_id' => Space::factory(),
            'name' => fake()->words(3, true),
            'token' => Str::random(64),
            'abilities' => ['*:read', '*:create', '*:update', '*:delete'],
            'expires_at' => fake()->optional(0.7)->dateTimeBetween('now', '+1 year'),
        ];
    }

    public function readOnly(): static
    {
        return $this->state(fn (array $attributes) => [
            'abilities' => ['*:read'],
        ]);
    }

    public function writeOnly(): static
    {
        return $this->state(fn (array $attributes) => [
            'abilities' => ['*:create', '*:update', '*:delete'],
        ]);
    }

    public function withAbilities(array $abilities): static
    {
        return $this->state(fn (array $attributes) => [
            'abilities' => $abilities,
        ]);
    }
}
