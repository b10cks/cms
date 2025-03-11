<?php

namespace Tests\Unit\Services\Database;


use App\Models\Management\SpaceConnection;
use App\Services\Database\ConnectionFactory;
use App\Services\Database\DatabaseConnectionService;
use DB;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Log;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(DatabaseConnectionService::class)]
class DatabaseConnectionServiceTest extends TestCase
{
    use LazilyRefreshDatabase;

    private DatabaseConnectionService $service;
    private ConnectionFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->factory = $this->createMock(ConnectionFactory::class);
        $this->service = new DatabaseConnectionService($this->factory);
    }

    #[Test]
    public function it_tests_connections_successfully(): void
    {
        // Arrange
        $connection = SpaceConnection::factory()->create();
        $mockDbConnection = $this->createMock(Connection::class);
        $mockPdo = $this->createMock(PDO::class);

        $mockDbConnection->method('getPdo')
            ->willReturn($mockPdo);

        $this->factory->method('make')
            ->with($connection)
            ->willReturn($mockDbConnection);

        // Act
        $result = $this->service->testConnection($connection);

        // Assert
        $this->assertTrue($result);
    }

    #[Test]
    public function it_handles_failed_connection_tests(): void
    {
        // Arrange
        $connection = SpaceConnection::factory()->create();

        $this->factory->method('make')
            ->willThrowException(new \Exception('Connection failed'));

        Log::shouldReceive('warning')
            ->once()
            ->with('Connection test failed', [
                'connection_id' => $connection->id,
                'error' => 'Connection failed'
            ]);

        // Act
        $result = $this->service->testConnection($connection);

        // Assert
        $this->assertFalse($result);
    }

    #[Test]
    public function it_clears_connections(): void
    {
        // Arrange
        $connection = SpaceConnection::factory()->create();
        $cacheKey = "db_connection:{$connection->id}";

        DB::shouldReceive('purge')
            ->once()
            ->with($connection->id);

        // Act
        $this->service->clearConnection($connection);
    }
}
