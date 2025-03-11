<?php

namespace Database\Factories\Management;

use App\Models\Management\Subscription;
use App\Models\Management\Space;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
{
    protected $model = Subscription::class;

    public function definition(): array
    {
        return [
            'space_id' => Space::factory(),
            'name' => fake()->words(2, true),
            'lemon_squeezy_id' => (string)fake()->unique()->numberBetween(1000, 9999),
            'status' => fake()->randomElement(['active', 'cancelled', 'expired']),
            'variant_id' => (string)fake()->numberBetween(1, 100),
            'product_id' => (string)fake()->numberBetween(1, 100),
            'quantity' => fake()->numberBetween(1, 5),
            'attributes' => [
                'plan' => fake()->randomElement(['starter', 'pro', 'enterprise']),
                'features' => fake()->randomElements(['api', 'webhooks', 'custom_domain'], 2),
            ],
            'renews_at' => fake()->dateTimeBetween('+1 month', '+2 months'),
            'ends_at' => null,
            'trial_ends_at' => fake()->optional()->dateTimeBetween('+1 week', '+2 weeks'),
        ];
    }
}
