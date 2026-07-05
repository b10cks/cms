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
            // Clean up any orphaned migration records (migrations that created tables
            // but weren't properly recorded in the migrations table)
            $this->cleanupOrphanedMigrations($migrationConnection);

            // Get list of pending migrations before running
            $pendingBefore = $this->getPendingMigrations($migrationConnection);

            $exitCode = Artisan::call('migrate', [
                '--path' => self::MIGRATION_PATH,
                '--force' => true,
                '--database' => $migrationConnection,
            ]);

            $output = trim(Artisan::output());

            // Check if any migrations were actually pending
            $pendingAfter = $this->getPendingMigrations($migrationConnection);

            if (!empty($pendingBefore)) {
                Log::info('Space database migration finished', [
                    'spaceConnection' => $connection->id,
                    'database' => $databaseName,
                    'pending_before' => count($pendingBefore),
                    'pending_after' => count($pendingAfter),
                    'exit_code' => $exitCode,
                    'output' => $output,
                ]);
            }

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
     * Whether the space database already has the core schema in place and all
     * required migrations have been applied.
     */
    public function isInitialized(SpaceConnection $connection): bool
    {
        $migrationConnection = $this->registerAdminConnection($connection, $this->databaseName($connection));

        try {
            // Check if sentinel tables exist
            foreach (self::SENTINEL_TABLES as $table) {
                if (!Schema::connection($migrationConnection)->hasTable($table)) {
                    return false;
                }
            }

            // Check if all required space migrations have been applied
            $pending = $this->getPendingMigrations($migrationConnection);
            return empty($pending);
        } finally {
            $this->forgetConnection($migrationConnection);
        }
    }

    /**
     * Clean up orphaned migration records — migrations that created tables but
     * weren't properly recorded in the migrations table. This can happen if
     * a migration partially succeeded or the migrations table was cleared.
     */
    private function cleanupOrphanedMigrations(string $connectionName): void
    {
        try {
            if (!Schema::connection($connectionName)->hasTable('migrations')) {
                return; // No migrations table yet, nothing to clean up
            }

            $path = base_path(self::MIGRATION_PATH);
            $files = glob($path . '/*.php');
            $migrationNames = array_map(fn ($f) => basename($f, '.php'), $files ?: []);
            sort($migrationNames);

            // Get applied migrations (format: "database/migrations/spaces/FILENAME")
            $appliedMigrations = DB::connection($connectionName)
                ->table('migrations')
                ->where('migration', 'like', '%spaces/%')
                ->pluck('migration')
                ->toArray();

            // Extract just the filename from the migration path
            $appliedNames = array_map(
                fn ($m) => basename($m),
                $appliedMigrations
            );

            // Find migrations that are in files but not recorded in database
            $unrecordedMigrations = array_diff($migrationNames, $appliedNames);

            if (empty($unrecordedMigrations)) {
                return; // All migrations are recorded
            }

            // Check if sentinel tables from later migrations exist
            $hasAssetCollections = Schema::connection($connectionName)->hasTable('asset_collections');

            foreach ($unrecordedMigrations as $migrationName) {
                // Skip if this is the asset_collections migration and its tables don't exist
                if (str_contains($migrationName, '000006') && !$hasAssetCollections) {
                    continue; // Tables not created, don't mark as applied
                }

                // Record the migration as applied
                $nextBatch = (DB::connection($connectionName)->table('migrations')->max('batch') ?? 0) + 1;
                DB::connection($connectionName)->table('migrations')->insert([
                    'migration' => "database/migrations/spaces/{$migrationName}",
                    'batch' => $nextBatch,
                ]);

                Log::info('Recorded untracked migration', [
                    'connection' => $connectionName,
                    'migration' => $migrationName,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Could not cleanup untracked migrations', [
                'error' => $e->getMessage(),
            ]);
            // Don't fail the migration if cleanup fails
        }
    }

    /**
     * Get list of pending migrations that haven't been applied yet.
     * Returns array of migration file names.
     */
    private function getPendingMigrations(string $connectionName): array
    {
        try {
            // Get all migration files
            $path = base_path(self::MIGRATION_PATH);
            $files = glob($path . '/*.php');
            $migrationNames = array_map(fn ($f) => basename($f, '.php'), $files ?: []);
            sort($migrationNames);

            // Get applied migrations from the database
            $appliedMigrations = DB::connection($connectionName)
                ->table('migrations')
                ->where('migration', 'like', '%spaces/%')
                ->pluck('migration')
                ->map(fn ($m) => basename($m, '.php'))
                ->toArray();

            // Return migrations that are in files but not in the applied list
            return array_diff($migrationNames, $appliedMigrations);
        } catch (\Throwable $e) {
            // If migrations table doesn't exist, all are pending
            Log::debug('Could not check pending migrations', ['error' => $e->getMessage()]);
            return [];
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
