<?php

namespace Database\Factories\Management;

use App\Models\Management\Invite;
use App\Models\Management\Space;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Str;

/**
 * @extends Factory<Invite>
 */
class InviteFactory extends Factory
{
    protected $model = Invite::class;

    public function definition(): array
    {
        return [
            'space_id' => Space::factory(),
            'invited_by' => User::factory(),
            'email' => fake()->safeEmail(),
            'role' => fake()->randomElement(['admin', 'member']),
            'token' => Str::random(32),
            'expires_at' => fake()->dateTimeBetween('+1 day', '+1 week'),
            'accepted_at' => null,
        ];
    }

    public function accepted(): static
    {
        return $this->state(fn(array $attributes) => [
            'accepted_at' => fake()->dateTimeBetween('-1 week'),
        ]);
    }
}
