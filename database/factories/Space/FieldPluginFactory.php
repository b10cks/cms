<?php

namespace Database\Factories\Space;

use App\Models\Space\FieldPlugin;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class FieldPluginFactory extends Factory
{
    protected $model = FieldPlugin::class;

    public function definition(): array
    {
        return [
            'external_id' => Str::uuid(),
            'name' => $this->faker->words(2, true),
            'handle' => $this->faker->unique()->slug(2),
            'description' => $this->faker->sentence(),
            'dev_mode' => false,
            'dev_url' => null,
            'manifest' => null,
            'is_active' => true,
        ];
    }

    public function published(?string $code = null): static
    {
        return $this->state(function () use ($code) {
            $code = $code ?? 'window.b10cksFieldPlugin={mount(){}}';

            return [
                'code' => $code,
                'code_hash' => hash('sha256', $code),
                'code_size' => strlen($code),
                'published_at' => now(),
            ];
        });
    }

    public function devMode(string $url = 'http://localhost:5173/plugin'): static
    {
        return $this->state(fn () => [
            'dev_mode' => true,
            'dev_url' => $url,
        ]);
    }
}
