<?php

namespace App\Services\Database;

use App\Models\Management\SpaceConnection;
use Config;
use DB;
use Illuminate\Database\ConnectionInterface;

/**
 * Registers space connections with the database manager. Warmed under Octane
 * (config/octane.php), so `$registered` outlives the per-request config
 * sandbox and an unchanged config keeps the worker's open PDO.
 */
class ConnectionFactory
{
    /**
     * Configs this worker registered, keyed by connection name.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $registered = [];

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

        $name = $connection->id;

        // Octane hands every request a fresh config clone, so the key must be
        // set again each time; the manager reads it whenever it (re)connects.
        // Only a config this worker has not seen (first use, rotated
        // credentials) drops the open connection.
        Config::set("database.connections.{$name}", $config);

        if (($this->registered[$name] ?? null) !== $config) {
            DB::purge($name);
            $this->registered[$name] = $config;
        }

        return DB::connection($name);
    }

    /**
     * Close the connection and forget its config.
     */
    public function release(string $name): void
    {
        DB::purge($name);
        unset($this->registered[$name]);

        $connections = Config::get('database.connections', []);
        unset($connections[$name]);
        Config::set('database.connections', $connections);
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
