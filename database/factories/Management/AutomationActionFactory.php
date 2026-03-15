<?php

namespace Database\Factories\Management;

use App\Models\Management\AutomationAction;
use App\Models\Management\Space;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AutomationAction>
 */
class AutomationActionFactory extends Factory
{
    protected $model = AutomationAction::class;

    public function definition(): array
    {
        return [
            'space_id' => Space::factory(),
            'name' => fake()->words(3, true),
            'description' => fake()->sentence(),
            'type' => 'webhook',
            'config' => [
                'method' => 'POST',
                'url' => fake()->url(),
                'headers' => [
                    'X-Request-Id' => Str::uuid()->toString(),
                ],
                'parameters' => [
                    'message' => fake()->sentence(),
                ],
            ],
            'secrets' => null,
            'is_active' => true,
            'last_executed_at' => fake()->optional()->dateTimeBetween('-1 month'),
            'last_execution_status' => fake()->optional()->randomElement(['completed', 'failed']),
            'last_execution_error' => null,
        ];
    }

    public function email(): self
    {
        return $this->state(fn () => [
            'type' => 'email',
            'config' => [
                'to' => [fake()->safeEmail()],
                'cc' => [],
                'bcc' => [],
                'subject' => fake()->sentence(),
                'body' => fake()->paragraph(),
            ],
        ]);
    }

    public function void(): self
    {
        return $this->state(fn () => [
            'type' => 'void',
            'config' => [
                'message' => fake()->sentence(),
            ],
        ]);
    }
}
