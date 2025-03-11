<?php

namespace Database\Factories\Management;

use App\Models\Management\Storage;
use App\Models\Management\Space;
use Illuminate\Database\Eloquent\Factories\Factory;

class StorageFactory extends Factory
{
    protected $model = Storage::class;

    public function definition(): array
    {
        return [
            'space_id' => Space::factory(),
            'state' => 'live',
            'name' => $this->faker->company() . ' Storage',
            'slug' => $this->faker->unique()->slug(2),
            'icon' => $this->faker->randomElement(['folder', 'cloud', 'database', 'server']),
            'color' => $this->faker->hexColor(),
            'description' => $this->faker->sentence(),
            'driver' => $this->faker->randomElement(['s3', 'sftp', 'local']),
            'config' => [
            ],
            'settings' => [
                'max_file_size' => 10 * 1024 * 1024, // 10MB
                'allowed_types' => ['jpg', 'png', 'pdf', 'doc']
            ],
            'is_default' => false,
            'is_managed' => false
        ];
    }

    public function s3(): self
    {
        return $this->state(fn (array $attributes) => [
            'driver' => 's3',
            'config' => [
                'key' => $this->faker->uuid(),
                'secret' => $this->faker->password(32),
                'region' => $this->faker->randomElement(['us-east-1', 'eu-west-1']),
                'bucket' => $this->faker->slug()
            ]
        ]);
    }

    public function sftp(): self
    {
        return $this->state(fn (array $attributes) => [
            'driver' => 'sftp',
            'config' => [
                'host' => $this->faker->domainName(),
                'username' => $this->faker->userName(),
                'password' => $this->faker->password(),
                'root' => '/var/www/storage'
            ]
        ]);
    }

    public function local(): self
    {
        return $this->state(fn (array $attributes) => [
            'driver' => 'local',
            'config' => [
                'root' => '/storage/app/public'
            ]
        ]);
    }

    public function managed(): self
    {
        return $this->state(fn (array $attributes) => [
            'is_managed' => true,
            'driver' => 's3',
            'config' => [
                'bucket' => "space-{$this->faker->slug()}",
                'region' => 'us-east-1'
            ]
        ]);
    }

    public function default(): self
    {
        return $this->state(fn (array $attributes) => [
            'is_default' => true
        ]);
    }
}
