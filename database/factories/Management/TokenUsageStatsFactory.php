<?php

namespace Database\Factories\Management;

use App\Enums\PeriodType;
use App\Models\Management\BaseToken;
use Illuminate\Database\Eloquent\Factories\Factory;

class TokenUsageStatsFactory extends Factory
{
    protected $model = \App\Models\Management\TokenUsageStats::class;

    public function definition(): array
    {
        $totalExecutions = fake()->numberBetween(10, 100);
        $failedExecutions = fake()->numberBetween(0, $totalExecutions);
        $successfulExecutions = $totalExecutions - $failedExecutions;

        return [
            'token_id' => BaseToken::factory(),
            'period_type' => fake()->randomElement(PeriodType::cases()),
            'period_date' => fake()->dateTimeBetween('-30 days')->format('Y-m-d'),
            'total_executions' => $totalExecutions,
            'successful_executions' => $successfulExecutions,
            'failed_executions' => $failedExecutions,
            'avg_duration_ms' => fake()->randomFloat(2, 100, 5000),
        ];
    }

    public function daily(): static
    {
        return $this->state(['period_type' => 'daily']);
    }

    public function weekly(): static
    {
        return $this->state(['period_type' => 'weekly']);
    }

    public function monthly(): static
    {
        return $this->state(['period_type' => 'monthly']);
    }
}
