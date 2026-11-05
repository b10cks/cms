<?php

namespace App\Services\Database;

use App\Models\Management\SpaceConnection;
use Illuminate\Database\ConnectionResolverInterface;

class DatabaseConnectionResolver implements ConnectionResolverInterface
{
    public function resolve(SpaceConnection $connection): array
    {
        $config = $connection->config;
        $driver = is_string($connection->driver) ? $connection->driver : $connection->driver->value;

        // Merged over the main connection's config, a saved space connection
        // without its own database would query the management database.
        // Unsaved ones are setup's server-level temp connections.
        if ($connection->exists && empty($config['database'] ?? null)) {
            throw new \RuntimeException("Space connection {$connection->id} has no database configured");
        }

        $default = config("database.connections.{$driver}");

        return array_merge(
            $default ?? [],
            $config ?? [],
            [
                'driver' => $driver,
                'name' => $connection->id,
            ]
        );
    }

    public function connection($name = null)
    {
        // TODO: Implement connection() method.
    }

    public function getDefaultConnection()
    {
        // TODO: Implement getDefaultConnection() method.
    }

    public function setDefaultConnection($name)
    {
        // TODO: Implement setDefaultConnection() method.
    }
}
