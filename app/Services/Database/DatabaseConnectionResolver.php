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
