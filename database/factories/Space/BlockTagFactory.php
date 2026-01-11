<?php

namespace Database\Factories\Space;

use App\Models\Space\BlockTag;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class BlockTagFactory extends Factory
{
    protected $model = BlockTag::class;

    public function definition(): array
    {
        return [
            'external_id' => Str::uuid(),
            'name' => $this->faker->unique()->words(2, true),
            'icon' => $this->faker->randomElement([
                'star', 'heart', 'home', 'user', 'settings',
                'search', 'plus', 'minus', 'edit', 'delete'
            ]),
            'color' => $this->faker->hexColor(),
        ];
    }

    public function withName(string $name): static
    {
        return $this->state(['name' => $name]);
    }

    public function withColor(string $color): static
    {
        return $this->state(['color' => $color]);
    }
}
