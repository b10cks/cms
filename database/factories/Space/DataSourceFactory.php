<?php

namespace Database\Factories\Space;

use App\Models\Space\DataSource;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class DataSourceFactory extends Factory
{
    protected $model = DataSource::class;

    public function definition(): array
    {
        $dimensions = [];
        $dimensionCount = $this->faker->numberBetween(1, 5);

        // Generate random language dimensions
        $languages = ['en' => 'English', 'fr' => 'French', 'de' => 'German', 'es' => 'Spanish', 'it' => 'Italian'];
        $keys = array_keys($languages);

        // Randomly select a subset of languages
        $selectedKeys = $this->faker->randomElements($keys, $this->faker->numberBetween(1, count($keys)));

        foreach ($selectedKeys as $key) {
            $dimensions[$key] = $languages[$key];
        }

        return [
            'external_id' => Str::uuid(),
            'name' => $this->faker->words(3, true),
            'slug' => $this->faker->slug(2),
            'description' => $this->faker->paragraph(),
            'dimensions' => $dimensions,
            'settings' => [
                'cache_ttl' => $this->faker->numberBetween(60, 3600),
                'fallback_dimension' => $this->faker->randomElement(array_keys($dimensions)),
            ],
            // Active by default. A 90% random default made every test that
            // reads a data source fail roughly one run in ten, since the
            // delivery endpoint 404s an inactive source. Use ->inactive()
            // when that is what the test is about.
            'is_active' => true,
        ];
    }

    /**
     * Configure the factory to create a data source with specific dimensions.
     *
     * @param array $dimensions
     * @return static
     */
    public function withDimensions(array $dimensions): static
    {
        return $this->state(function (array $attributes) use ($dimensions) {
            return [
                'dimensions' => $dimensions,
            ];
        });
    }

    /**
     * Configure the factory to create a data source with language dimensions.
     *
     * @return static
     */
    public function withLanguageDimensions(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'dimensions' => [
                    'en' => 'English',
                    'fr' => 'French',
                    'de' => 'German',
                    'es' => 'Spanish',
                ],
                'settings' => [
                    'cache_ttl' => 3600,
                    'fallback_dimension' => 'en',
                ],
            ];
        });
    }

    /**
     * Configure the factory to create a data source with region dimensions.
     *
     * @return static
     */
    public function withRegionDimensions(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'dimensions' => [
                    'us' => 'United States',
                    'eu' => 'Europe',
                    'asia' => 'Asia',
                    'global' => 'Global',
                ],
                'settings' => [
                    'cache_ttl' => 3600,
                    'fallback_dimension' => 'global',
                ],
            ];
        });
    }

    /**
     * Configure the factory to create an inactive data source.
     *
     * @return static
     */
    public function inactive(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'is_active' => false,
            ];
        });
    }
}
