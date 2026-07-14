<?php

namespace Database\Factories\Space;

use App\Models\Space\Redirect;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Redirect>
 */
class RedirectFactory extends Factory
{
    protected $model = Redirect::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'external_id' => Str::uuid(),
            'source' => $this->faker->unique()->regexify('\/[a-z-]{3,15}'),
            'target' => $this->faker->unique()->regexify('\/[a-z-]{3,15}'),
            'status_code' => $this->faker->randomElement([301, 302, 307, 308]),
            'hits' => $this->faker->numberBetween(0, 1000),
            'last_used_at' => $this->faker->optional()->dateTimeBetween('-1 year', 'now'),
        ];
    }

    /**
     * Create permanent redirects (301)
     */
    public function permanent(): static
    {
        return $this->state(fn (array $attributes) => [
            'status_code' => 301,
        ]);
    }

    /**
     * Create temporary redirects (302)
     */
    public function temporary(): static
    {
        return $this->state(fn (array $attributes) => [
            'status_code' => 302,
        ]);
    }

    /**
     * Create unused redirects (no hits)
     */
    public function unused(): static
    {
        return $this->state(fn (array $attributes) => [
            'hits' => 0,
            'last_used_at' => null,
        ]);
    }

    /**
     * Create frequently used redirects
     */
    public function popular(): static
    {
        return $this->state(fn (array $attributes) => [
            'hits' => $this->faker->numberBetween(500, 5000),
            'last_used_at' => $this->faker->dateTimeBetween('-1 month', 'now'),
        ]);
    }
}