<?php

namespace Database\Factories\Space;

use App\Models\Space\BlockFolder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class BlockFolderFactory extends Factory
{
    protected $model = BlockFolder::class;

    public function definition(): array
    {
        $name = $this->faker->words(2, true);

        return [
            'external_id' => Str::uuid(),
            'name' => Str::title($name),
            'description' => $this->faker->optional()->sentence(),
        ];
    }

    public function withName(string $name): static
    {
        return $this->state(['name' => $name]);
    }

    public function withDescription(string $description): static
    {
        return $this->state(['description' => $description]);
    }
}
