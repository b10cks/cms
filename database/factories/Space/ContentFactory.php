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
        // contents.current_version_id is NOT NULL, so a content always needs a
        // version — but the version factory defaults content_id back to a new
        // content, and the two recurse until the memory limit. Pinning the id
        // here lets the version point back without spawning another content.
        $id = strtolower((string) Str::ulid());

        return [
            'id' => $id,
            'external_id' => Str::uuid(),
            'block_id' => Block::factory(),
            'parent_id' => null,
            'position' => 0,
            'name' => $this->faker->word(),
            'slug' => $this->faker->slug(),
            'full_slug' => $this->faker->slug(),
            'language_iso' => 'en',
            'i18n_parent_id' => null,
            'settings' => [],
            'current_version_id' => ContentVersion::factory()->state(['content_id' => $id]),
            'published_version_id' => null,
            'searchable_content' => $this->faker->paragraph(),
            'published_at' => null,
            'first_published_at' => null,
        ];
    }
}
