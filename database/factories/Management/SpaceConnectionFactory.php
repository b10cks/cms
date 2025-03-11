<?php

namespace Database\Factories\Management;

use App\Enums\ConnectionDriver;
use App\Models\Management\Space;
use App\Models\Management\SpaceConnection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Str;

/**
 * @extends Factory<SpaceConnection>
 */
class SpaceConnectionFactory extends Factory
{
    protected $model = SpaceConnection::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $driver = $this->faker->randomElement(ConnectionDriver::cases());
        $name = $this->faker->unique()->company() . ' DB';

        return [
            'name' => $name,
            'state' => 'draft',
            'space_id' => Space::factory(),
            'description' => $this->faker->sentence(),
            'driver' => $driver,
            'config' => $this->getConnectionConfig($driver),
            'settings' => $this->getConnectionSettings($driver),
        ];
    }

    protected function getConnectionConfig(ConnectionDriver $driver): array
    {
        $baseConfig = [
            'host' => $this->faker->domainName(),
            'port' => $driver->defaultPort(),
            'database' => 'db_' . Str::lower(Str::random(8)),
            'username' => 'user_' . Str::lower(Str::random(8)),
            'password' => Str::random(16),
        ];

        return array_merge(
            $baseConfig,
            $this->getDriverSpecificConfig($driver)
        );
    }

    protected function getDriverSpecificConfig(ConnectionDriver $driver): array
    {
        return match ($driver) {
            ConnectionDriver::MYSQL => [
                'timezone' => $this->faker->timezone(),
            ],
            ConnectionDriver::PGSQL => [
                'schema' => 'public',
            ],
            ConnectionDriver::MONGODB => [
                'dsn' => sprintf(
                    'mongodb://%s:%s@%s:%d/%s',
                    'user_' . Str::lower(Str::random(8)),
                    Str::random(16),
                    $this->faker->domainName(),
                    27017,
                    'db_' . Str::lower(Str::random(8))
                ),
            ],
            ConnectionDriver::SQLITE => [
                'database' => database_path('database.sqlite'),
            ],
        };
    }

    protected function getConnectionSettings(ConnectionDriver $driver): array
    {
        return [
            'max_connections' => $this->faker->numberBetween(5, 50),
            'timeout' => $this->faker->numberBetween(5, 30),
            'retry_attempts' => $this->faker->numberBetween(1, 5),
            'retry_delay' => $this->faker->numberBetween(1, 5),
            'pool' => [
                'min' => $this->faker->numberBetween(1, 5),
                'max' => $this->faker->numberBetween(5, 20),
            ],
            'monitoring' => [
                'enabled' => $this->faker->boolean(80),
                'interval' => $this->faker->numberBetween(30, 300),
                'alerts' => [
                    'latency_threshold' => $this->faker->numberBetween(100, 1000),
                    'error_threshold' => $this->faker->numberBetween(3, 10),
                ],
            ],
        ];
    }

    public function mysql(): static
    {
        return $this->state(fn (array $attributes) => [
            'driver' => ConnectionDriver::MYSQL,
        ]);
    }

    public function postgresql(): static
    {
        return $this->state(fn (array $attributes) => [
            'driver' => ConnectionDriver::PGSQL,
        ]);
    }

    public function mongodb(): static
    {
        return $this->state(fn (array $attributes) => [
            'driver' => ConnectionDriver::MONGODB,
        ]);
    }

    public function sqlite(): static
    {
        return $this->state(fn (array $attributes) => [
            'driver' => ConnectionDriver::SQLITE,
        ]);
    }
}
