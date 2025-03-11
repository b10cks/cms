<?php

namespace Database\Factories\Management;

use App\Models\Management\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

class TeamFactory extends Factory
{
    protected $model = Team::class;

    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'icon' => fake()->randomElement(['building', 'users', 'briefcase', 'handshake']),
            'color' => fake()->hexColor(),
            'description' => fake()->sentence(),
            'type' => fake()->randomElement([null, 'partner', 'reseller', 'affiliate']),
            'settings' => [
                'features' => fake()->randomElements(['advanced_reporting', 'custom_branding', 'api_access'], 2),
            ],
        ];
    }

    public function withParent(?Team $parent = null): self
    {
        return $this->state(fn() => [
            'parent_id' => $parent?->id ?? Team::factory(),
        ]);
    }

    public function withChildren(int $count = 2): self
    {
        return $this->afterCreating(function (Team $team) use ($count) {
            Team::factory()->count($count)->create([
                'parent_id' => $team->id
            ]);
        });
    }
}
