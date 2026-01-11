<?php

namespace Database\Factories\Space;

use App\Models\Space\DataEntry;
use App\Models\Space\DataSource;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class DataEntryFactory extends Factory
{
    protected $model = DataEntry::class;

    public function definition(): array
    {
        return [
            'external_id' => Str::uuid(),
            'data_source_id' => DataSource::factory(),
            'key' => $this->faker->word(),
            'value' => $this->faker->sentence(),
            'dimensions' => [], // Default empty dimensions
            'is_active' => $this->faker->boolean(90), // 90% chance of being active
        ];
    }

    /**
     * Configure the factory to create a data entry with dimension values.
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
     * Configure the factory to create a data entry with random language dimension values.
     *
     * @param DataSource|null $dataSource
     * @return static
     */
    public function withRandomLanguageDimensions(?DataSource $dataSource = null): static
    {
        return $this->state(function (array $attributes) use ($dataSource) {
            $dimensions = [];

            // If a data source is provided, use its dimensions
            if ($dataSource && !empty($dataSource->dimensions)) {
                $dimensionKeys = array_keys($dataSource->dimensions);
                $selectedKeys = $this->faker->randomElements($dimensionKeys, $this->faker->numberBetween(1, count($dimensionKeys)));

                foreach ($selectedKeys as $key) {
                    $dimensions[$key] = $this->faker->sentence();
                }
            } else {
                // Otherwise, generate random language dimensions
                $languages = ['en', 'fr', 'de', 'es', 'it'];
                $selectedLanguages = $this->faker->randomElements($languages, $this->faker->numberBetween(1, count($languages)));

                foreach ($selectedLanguages as $language) {
                    $dimensions[$language] = $this->faker->sentence();
                }
            }

            return [
                'dimensions' => $dimensions,
            ];
        });
    }

    /**
     * Configure the factory to create an inactive data entry.
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

    /**
     * Configure the factory to fill all dimensions from a data source.
     *
     * @param DataSource $dataSource
     * @return static
     */
    public function fillAllDimensions(DataSource $dataSource): static
    {
        return $this->state(function (array $attributes) use ($dataSource) {
            $dimensions = [];

            if (!empty($dataSource->dimensions)) {
                foreach (array_keys($dataSource->dimensions) as $key) {
                    $dimensions[$key] = $this->faker->sentence();
                }
            }

            return [
                'data_source_id' => $dataSource->id,
                'dimensions' => $dimensions,
            ];
        });
    }
}
