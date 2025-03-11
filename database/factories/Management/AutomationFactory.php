<?php

namespace Database\Factories\Management;

use App\Models\Management\Automation;
use App\Models\Management\Table;
use Illuminate\Database\Eloquent\Factories\Factory;
use Str;

/**
 * @extends Factory<Automation>
 */
class AutomationFactory extends Factory
{
    protected $model = Automation::class;

    public function definition(): array
    {
        return [
            'table_id' => Table::factory(),
            'name' => fake()->words(3, true),
            'description' => fake()->sentence(),
            'trigger' => [
                'type' => fake()->randomElement([
                    'on_insert', 'on_update', 'on_delete',
                    'time_based', 'manual', 'field_based'
                ]),
                'config' => null
            ],
            'action' => [
                'type' => 'webhook',
                'config' => [
                    'method' => 'POST',
                    'url' => fake()->url(),
                    'headers' => [
                        'X-Api-Key' => Str::random()
                    ]
                ]
            ],
            'is_active' => true,
            'secrets' => null,
            'last_triggered_at' => fake()->dateTimeBetween('-1 month'),
        ];
    }

    public function fieldBased(): self
    {
        return $this->state(fn (array $attributes) => [
            'trigger' => [
                'type' => 'field_based',
                'configuration' => [
                    'field' => 'status',
                    'operator' => 'eq',
                    'value' => 'completed'
                ]
            ]
        ]);
    }

    public function timeBased(): self
    {
        return $this->state(fn (array $attributes) => [
            'trigger' => [
                'type' => 'time_based',
                'configuration' => [
                    'schedule' => '0 * * * *' // Every hour
                ]
            ]
        ]);
    }

    public function email(): self
    {
        return $this->state(fn (array $attributes) => [
            'action' => [
                'type' => 'email',
                'configuration' => [
                    'to' => [fake()->safeEmail()],
                    'cc' => [],
                    'bcc' => [],
                    'subject' => fake()->sentence(),
                    'body' => fake()->paragraph()
                ]
            ]
        ]);
    }
}
