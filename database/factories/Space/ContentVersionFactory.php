<?php

namespace Database\Factories\Space;

use App\Models\Space\Content;
use App\Models\Space\ContentVersion;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ContentVersionFactory extends Factory
{
    protected $model = ContentVersion::class;

    public function definition(): array
    {
        return [
            'external_id' => Str::uuid(),
            'content_id' => Content::factory(),
            'parent_id' => null,
            'message' => $this->faker->paragraph(),
            'content' => [],
            'asset_ids' => [],
            'relation_ids' => [],
            'link_ids' => [],
        ];
    }
}
