<?php

namespace Database\Factories\Management;

use App\Models\Management\BaseToken;
use App\Models\Management\TokenExecution;
use Illuminate\Database\Eloquent\Factories\Factory;

class TokenExecutionFactory extends Factory
{
    protected $model = TokenExecution::class;

    public function definition(): array
    {
        $startedAt = fake()->dateTimeBetween('-30 days');
        $completedAt = fake()->boolean(80)
            ? (clone $startedAt)->modify('+' . rand(1, 5000) . ' milliseconds')
            : null;

        return [
            'token_id' => BaseToken::factory(),
            'status' => fake()->randomElement(['completed', 'failed', 'running']),
            'context' => ['trigger_data' => fake()->words(3, true)],
            'result' => $completedAt ? ['output' => fake()->sentence] : null,
            'error' => !$completedAt && fake()->boolean(70) ? fake()->sentence : null,
            'started_at' => $startedAt,
            'completed_at' => $completedAt,
            'created_at' => $startedAt,
        ];
    }

    public function completed(): static
    {
        return $this->state(function (array $attributes) {
            $startedAt = $attributes['started_at'] ?? now()->subMinutes(rand(1, 60));
            $completedAt = (clone $startedAt)->modify('+' . rand(1, 5000) . ' milliseconds');

            return [
                'status' => 'completed',
                'result' => ['output' => fake()->sentence],
                'error' => null,
                'started_at' => $startedAt,
                'completed_at' => $completedAt,
            ];
        });
    }

    public function failed(): static
    {
        return $this->state(function (array $attributes) {
            $startedAt = $attributes['started_at'] ?? now()->subMinutes(rand(1, 60));
            $completedAt = (clone $startedAt)->modify('+' . rand(1, 5000) . ' milliseconds');

            return [
                'status' => 'failed',
                'result' => null,
                'error' => fake()->sentence,
                'started_at' => $startedAt,
                'completed_at' => $completedAt,
            ];
        });
    }

    public function running(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'status' => 'running',
                'result' => null,
                'error' => null,
                'started_at' => now()->subSeconds(rand(1, 300)),
                'completed_at' => null,
            ];
        });
    }
}
