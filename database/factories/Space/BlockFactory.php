<?php

namespace Database\Factories\Space;

use App\Models\Space\Block;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class BlockFactory extends Factory
{
    protected $model = Block::class;

    public function definition(): array
    {
        return [
            'external_id' => Str::uuid(),
            'name' => $this->faker->word(),
            'slug' => $this->faker->slug(),
            'icon' => $this->faker->randomElement(['star', 'heart', 'home', 'user', 'settings']),
            'color' => $this->faker->hexColor(),
            'type' => $this->faker->randomElement(['text', 'image', 'media', 'form']),
            'description' => $this->faker->paragraph(),
            'preview_template' => null,
            'schema' => [],
            'editor' => [],
            'tags' => [],
            'folder_id' => null,
        ];
    }
}
