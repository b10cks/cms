<?php

namespace Database\Factories\Management;

use App\Models\Management\AutomationAction;
use App\Models\Management\Automation;
use App\Models\Management\Space;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Automation>
 */
class AutomationFactory extends Factory
{
    protected $model = Automation::class;

    public function definition(): array
    {
        $triggerType = fake()->randomElement([
            'on_insert',
            'on_update',
            'on_delete',
            'time_based',
            'manual',
        ]);

        return [
            'space_id' => Space::factory(),
            'name' => fake()->words(3, true),
            'description' => fake()->sentence(),
            'action_id' => function (array $attributes) {
                return AutomationAction::factory()
                    ->create(['space_id' => $attributes['space_id']])
                    ->id;
            },
            'trigger_type' => $triggerType,
            'trigger_config' => $triggerType === 'time_based'
                ? ['schedule' => '0 * * * *']
                : [],
            'is_active' => true,
            'execution_limit' => fake()->optional()->numberBetween(10, 500),
            'execution_count' => fake()->numberBetween(0, 10),
            'last_triggered_at' => fake()->dateTimeBetween('-1 month'),
        ];
    }

    public function forSpace(Space $space): self
    {
        return $this->state([
            'space_id' => $space->id,
            'action_id' => AutomationAction::factory()->create(['space_id' => $space->id])->id,
        ]);
    }

    public function timeBased(): self
    {
        return $this->state(fn () => [
            'trigger_type' => 'time_based',
            'trigger_config' => [
                'schedule' => '0 * * * *',
            ],
        ]);
    }
}
