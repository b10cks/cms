<?php

namespace Database\Factories\Space;

use App\Models\Space\AssetCollection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class AssetCollectionFactory extends Factory
{
    protected $model = AssetCollection::class;

    public function definition(): array
    {
        return [
            'external_id' => Str::uuid(),
            'name' => $this->faker->words(3, true),
            'description' => $this->faker->boolean(50) ? $this->faker->sentence() : null,
            'icon' => null,
            'color' => $this->faker->boolean(50) ? $this->faker->hexColor() : null,
            'type' => AssetCollection::TYPE_MANUAL,
            'rules' => null,
            'settings' => null,
        ];
    }

    public function smart(array $rules): self
    {
        return $this->state(fn () => [
            'type' => AssetCollection::TYPE_SMART,
            'rules' => $rules,
        ]);
    }
}
