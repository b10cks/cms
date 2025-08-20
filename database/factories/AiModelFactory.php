<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class AiModelFactory extends Factory
{
    public function definition(): array
    {
        $providers = ['OpenAI', 'Anthropic', 'Google', 'Meta', 'Mistral', 'Cohere'];
        $commonTags = ['production', 'beta', 'experimental', 'fast', 'accurate', 'multimodal', 'creative'];
        $selectedTags = $this->faker->randomElements(
            $commonTags,
            $this->faker->numberBetween(1, 4)
        );

        return [
            'name' => $this->faker->words(2, true) . ' ' . $this->faker->randomElement(['Pro', 'Turbo', 'Mini', 'Ultra']),
            'model' => $this->faker->slug(2) . '-' . $this->faker->randomElement(['3.5', '4.0', '4.5', '5.0']) . '-' . $this->faker->randomElement(['turbo', 'mini', 'ultra']),
            'tags' => $selectedTags,
            'token_multiplier' => $this->faker->randomFloat(3, 0.1, 5.0),
            'is_free' => $this->faker->boolean(20),
            'is_active' => $this->faker->boolean(85),
            'description' => $this->faker->paragraph(),
            'provider' => $this->faker->randomElement($providers),
            'settings' => [
                'temperature' => $this->faker->randomFloat(1, 0.0, 2.0),
                'top_p' => $this->faker->randomFloat(2, 0.1, 1.0),
                'frequency_penalty' => $this->faker->randomFloat(1, 0.0, 2.0),
                'presence_penalty' => $this->faker->randomFloat(1, 0.0, 2.0),
            ],
        ];
    }
}
