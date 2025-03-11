<?php

namespace App\Services\Database;

use App\Models\Management\SpaceConnection;
use Config;
use DB;
use Illuminate\Database\ConnectionInterface;

class ConnectionFactory
{
    public function __construct(
        private DatabaseConnectionResolver $resolver
    )
    {
    }

    public function make(SpaceConnection $connection): ConnectionInterface
    {
        $config = $this->resolver->resolve($connection);
        // ensure sqlite database is created
        if ($connection->driver === 'sqlite') {
            $config['database'] = $this->createSqliteDatabase($config['database']);
        }

        Config::set("database.connections.{$connection->id}", $config);
        DB::purge($connection->id);

        return DB::connection($connection->id);
    }

    private function createSqliteDatabase(string $database): string
    {
        if (!str_starts_with($database, '/')) {
            $database = storage_path("app/{$database}");
        }
        if (!file_exists($database)) {
            touch($database);
        }

        return $database;
    }
}
