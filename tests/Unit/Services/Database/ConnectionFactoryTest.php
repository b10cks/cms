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
    public function it_keeps_the_open_connection_when_the_config_is_unchanged(): void
    {
        $connection = SpaceConnection::factory()->mysql()->create();
        $config = ['driver' => 'mysql', 'database' => 'test_db'];

        $this->resolver->method('resolve')->willReturn($config);

        $first = $this->factory->make($connection);

        // Octane starts each request from a fresh copy of the boot config.
        Config::offsetUnset("database.connections.{$connection->id}");

        $second = $this->factory->make($connection);

        $this->assertSame($first, $second);
        $this->assertEquals($config, Config::get("database.connections.{$connection->id}"));
    }

    #[Test]
    public function it_reconnects_when_the_config_changes(): void
    {
        $connection = SpaceConnection::factory()->mysql()->create();
        $rotated = ['driver' => 'mysql', 'database' => 'test_db', 'password' => 'rotated'];

        $this->resolver->method('resolve')->willReturnOnConsecutiveCalls(
            ['driver' => 'mysql', 'database' => 'test_db', 'password' => 'old'],
            $rotated,
        );

        $first = $this->factory->make($connection);
        $second = $this->factory->make($connection);

        $this->assertNotSame($first, $second);
        $this->assertEquals($rotated, Config::get("database.connections.{$connection->id}"));
    }
}
