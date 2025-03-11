<?php

namespace App\Services\Database;

use App\Models\Management\Space;
use App\Models\Management\SpaceConnection;
use DB;
use Illuminate\Database\ConnectionInterface;
use Log;
use Throwable;

class DatabaseConnectionService
{
    public function __construct(
        private readonly ConnectionFactory $factory
    )
    {
    }

    public function getDefaultConnection(Space $space): ConnectionInterface
    {
        try {
            $connection = $space->defaultConnection->first();

            return $this->getConnection($connection);
        } catch (Throwable $e) {
            Log::error('Failed to establish database connection', [
                'space_id' => $space->id,
                'error' => $e->getMessage(),
            ]);

            throw new DatabaseConnectionException(
                "Failed to establish database connection: {$e->getMessage()}",
                previous: $e
            );
        }
    }

    public function getConnection(SpaceConnection $connection): ConnectionInterface
    {
        return $this->factory->make($connection);
    }

    /**
     * Test if a connection is valid
     */
    public function testConnection(SpaceConnection $connection): bool
    {
        try {
            $conn = $this->factory->make($connection);
            $conn->getPdo();

            return true;
        } catch (Throwable $e) {
            Log::warning('Connection test failed', [
                'connection_id' => $connection->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function clearConnection(SpaceConnection $connection): void
    {
        DB::purge($connection->id);
    }
}
