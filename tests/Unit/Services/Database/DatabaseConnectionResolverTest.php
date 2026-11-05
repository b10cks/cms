<?php

namespace Tests\Unit\Services\Database;

use App\Models\Management\SpaceConnection;
use App\Services\Database\DatabaseConnectionResolver;
use Config;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;


#[CoversClass(DatabaseConnectionResolver::class)]
class DatabaseConnectionResolverTest extends TestCase
{
    use LazilyRefreshDatabase;

    private DatabaseConnectionResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new DatabaseConnectionResolver();
    }

    #[Test]
    public function it_resolves_connection_config(): void
    {
        // Arrange
        $connection = SpaceConnection::factory()->mysql()->create([
            'config' => [
                'host' => 'custom-host',
                'database' => 'custom-db'
            ]
        ]);

        // Set default MySQL config
        Config::set('database.connections.mysql', [
            'driver' => 'mysql',
            'host' => 'default-host',
            'port' => 3306
        ]);

        // Act
        $config = $this->resolver->resolve($connection);

        // Assert
        $this->assertEquals([
            'driver' => 'mysql',
            'host' => 'custom-host',
            'port' => 3306,
            'database' => 'custom-db',
            'name' => $connection->id
        ], $config);
    }

    #[Test]
    public function a_saved_connection_without_a_database_never_falls_back_to_the_main_one(): void
    {
        $connection = SpaceConnection::factory()->mysql()->create(['config' => ['username' => 'space_user']]);

        $this->expectException(\RuntimeException::class);

        $this->resolver->resolve($connection);
    }

    #[Test]
    public function it_falls_back_to_empty_array_for_missing_default_config(): void
    {
        // Arrange
        $connection = SpaceConnection::factory()->create([
            'driver' => 'missing-driver',
            'config' => ['custom' => 'config', 'database' => 'space_db']
        ]);

        // Act
        $config = $this->resolver->resolve($connection);

        // Assert
        $this->assertEquals([
            'driver' => 'missing-driver',
            'custom' => 'config',
            'database' => 'space_db',
            'name' => $connection->id
        ], $config);
    }
}
