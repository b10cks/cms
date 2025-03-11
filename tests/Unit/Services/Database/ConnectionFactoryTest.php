<?php

namespace Tests\Unit\Services\Database;

use App\Models\Management\SpaceConnection;
use App\Services\Database\ConnectionFactory;
use App\Services\Database\DatabaseConnectionResolver;
use Config;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(ConnectionFactory::class)]
class ConnectionFactoryTest extends TestCase
{
    use LazilyRefreshDatabase;

    private ConnectionFactory $factory;
    private DatabaseConnectionResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = $this->createMock(DatabaseConnectionResolver::class);
        $this->factory = new ConnectionFactory($this->resolver);
    }

    #[Test]
    public function it_creates_new_database_connections(): void
    {
        // Arrange
        $connection = SpaceConnection::factory()->create();
        $config = ['driver' => 'mysql', 'database' => 'test_db'];

        $this->resolver->method('resolve')
            ->with($connection)
            ->willReturn($config);

        // Act
        $result = $this->factory->make($connection);

        // Assert
        $this->assertInstanceOf(Connection::class, $result);
        $this->assertEquals($config, Config::get("database.connections.{$connection->id}"));
    }

    #[Test]
    public function it_purges_existing_connections_before_creating_new_ones(): void
    {
        // Arrange
        $connection = SpaceConnection::factory()->create();
        $config = ['driver' => 'mysql', 'database' => 'test_db'];

        $this->resolver->method('resolve')
            ->with($connection)
            ->willReturn($config);

        // Create initial connection
        $this->factory->make($connection);

        // Act - Create another connection with same ID
        $result = $this->factory->make($connection);

        // Assert
        $this->assertInstanceOf(Connection::class, $result);
        $this->assertEquals($config, Config::get("database.connections.{$connection->id}"));
    }
}
