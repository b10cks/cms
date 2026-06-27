<?php

namespace App\Services\Database;

use App\Models\Management\SpaceConnection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Runs and verifies the per-space schema migration.
 *
 * The migration runs with the administrative (base) credentials rather than the
 * restricted space user, whose grants may lack table-creation privileges
 * (Postgres) or be shadowed by host-specific/anonymous accounts (MySQL) —
 * either of which previously left the database created but empty. Shared by
 * SetupConnection (initial setup) and the spaces:repair-databases backfill.
 */
class SpaceDatabaseMigrator
{
    private const MIGRATION_PATH = 'database/migrations/spaces';

    /**
     * Tables that must exist once the space schema has migrated.
     */
    private const SENTINEL_TABLES = ['blocks', 'contents'];

    public function __construct(
        private readonly DatabaseConnectionService $connections,
    ) {
    }

    /**
     * Migrate the space database. Idempotent: already-applied migrations are
     * skipped, so this can also repair a partially-migrated database.
     */
    public function migrate(SpaceConnection $connection): void
    {
        $databaseName = $this->databaseName($connection);
        $path = base_path(self::MIGRATION_PATH);

        if (!is_dir($path) || empty(glob($path . '/*.php'))) {
            throw new DatabaseConnectionException(
                "No space migrations found at {$path}; cannot initialize the space database."
            );
        }

        $migrationConnection = $this->registerAdminConnection($connection, $databaseName);

        try {
            $exitCode = Artisan::call('migrate', [
                '--path' => self::MIGRATION_PATH,
                '--force' => true,
                '--database' => $migrationConnection,
            ]);

            $output = trim(Artisan::output());

            Log::info('Space database migration finished', [
                'spaceConnection' => $connection->id,
                'database' => $databaseName,
                'exit_code' => $exitCode,
                'output' => $output,
            ]);

            if ($exitCode !== 0) {
                throw new DatabaseConnectionException(
                    "Space database migration failed (exit {$exitCode}): {$output}"
                );
            }

            $this->assertInitialized($migrationConnection, $output);
        } finally {
            $this->forgetConnection($migrationConnection);
        }
    }

    /**
     * Whether the space database already has the core schema in place.
     */
    public function isInitialized(SpaceConnection $connection): bool
    {
        $migrationConnection = $this->registerAdminConnection($connection, $this->databaseName($connection));

        try {
            foreach (self::SENTINEL_TABLES as $table) {
                if (!Schema::connection($migrationConnection)->hasTable($table)) {
                    return false;
                }
            }

            return true;
        } finally {
            $this->forgetConnection($migrationConnection);
        }
    }

    private function databaseName(SpaceConnection $connection): string
    {
        $name = $connection->config['database'] ?? null;

        if (empty($name)) {
            throw new DatabaseConnectionException(
                "Connection {$connection->id} has no database configured; cannot migrate."
            );
        }

        return $name;
    }

    /**
     * Register a transient connection that targets the space database using the
     * administrative base credentials (full privileges), returning its name.
     */
    private function registerAdminConnection(SpaceConnection $connection, string $databaseName): string
    {
        $admin = new SpaceConnection([
            'driver' => $connection->driver,
            'config' => ['database' => $databaseName],
        ]);
        $admin->id = $connection->id . '_migrate';

        $this->connections->getConnection($admin);

        return $admin->id;
    }

    private function assertInitialized(string $connectionName, string $output): void
    {
        foreach (self::SENTINEL_TABLES as $table) {
            if (!Schema::connection($connectionName)->hasTable($table)) {
                throw new DatabaseConnectionException(
                    "Space database migration reported success but the '{$table}' table is missing. Output: {$output}"
                );
            }
        }
    }

    private function forgetConnection(string $connectionName): void
    {
        DB::purge($connectionName);
        Config::offsetUnset("database.connections.{$connectionName}");
    }
}
