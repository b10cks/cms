<?php

namespace Database\Factories\Space;

use App\Models\Space\Release;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Release>
 */
class ReleaseFactory extends Factory
{
    protected $model = Release::class;

    public function definition(): array
    {
        return [
            'external_id' => Str::uuid(),
            'name' => $this->faker->words(3, true),
            'description' => $this->faker->sentence(),
            'settings' => [],
            'owner_id' => null,
            'publish_at' => null,
            'committed_at' => null,
            'published_at' => null,
        ];
    }
}
