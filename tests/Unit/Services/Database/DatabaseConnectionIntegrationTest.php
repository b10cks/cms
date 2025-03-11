<?php

namespace Tests\Unit\Services\Database;

use App\Models\Management\Table;
use App\Models\Management\Space;
use App\Models\Management\SpaceConnection;
use App\Services\Database\DatabaseConnectionService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;


#[CoversClass(DatabaseConnectionService::class)]
class DatabaseConnectionIntegrationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private DatabaseConnectionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(DatabaseConnectionService::class);
    }

    #[Test]
    public function it_establishes_real_database_connections(): void
    {
        $space = Space::factory()->create();

        // Arrange
        $connection = SpaceConnection::factory()->mysql()->create([
            'driver' => env('DB_CONNECTION', 'sqlite'),
            'space_id' => $space->id,
            'is_default' => true,
            'config' => [
                'host' => env('DB_HOST', '127.0.0.1'),
                'port' => env('DB_PORT', '3306'),
                'database' => env('DB_DATABASE', 'forge'),
                'username' => env('DB_USERNAME', 'forge'),
                'password' => env('DB_PASSWORD', ''),
            ]
        ]);

        // Act
        $dbConnection = $this->service->getDefaultConnection($space);

        // Assert
        $this->assertTrue($dbConnection->getPdo() instanceof PDO);

        // Test running a simple query
        $result = $dbConnection->select('SELECT 1 as test');
        $this->assertEquals(1, $result[0]->test);
    }

}
