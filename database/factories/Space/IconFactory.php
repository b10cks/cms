<?php

namespace Database\Factories\Space;

use App\Models\Space\Icon;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class IconFactory extends Factory
{
    protected $model = Icon::class;

    public function definition(): array
    {
        $key = $this->faker->unique()->slug(2);

        return [
            'external_id' => (string) Str::uuid(),
            'key' => $key,
            'name' => Str::headline($key),
            'description' => $this->faker->optional()->sentence(),
            'body' => '<path d="M12 2 2 7l10 5 10-5z" fill="currentColor"/>',
            'width' => 24,
            'height' => 24,
            'tags' => $this->faker->boolean(50) ? $this->faker->words(2) : null,
        ];
    }
}
