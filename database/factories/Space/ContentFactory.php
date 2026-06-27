<?php

namespace Database\Factories\Space;

use App\Models\Space\Block;
use App\Models\Space\Content;
use App\Models\Space\ContentVersion;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ContentFactory extends Factory
{
    protected $model = Content::class;

    public function definition(): array
    {
        return [
            'external_id' => Str::uuid(),
            'block_id' => Block::factory(),
            'parent_id' => null,
            'position' => 0,
            'name' => $this->faker->word(),
            'slug' => $this->faker->slug(),
            'full_slug' => $this->faker->slug(),
            'language_iso' => 'en',
            'i18n_parent_id' => null,
            'content' => [],
            'settings' => [],
            'current_version_id' => ContentVersion::factory(),
            'published_version_id' => null,
            'searchable_content' => $this->faker->paragraph(),
            'published_at' => null,
            'first_published_at' => null,
        ];
    }
}
