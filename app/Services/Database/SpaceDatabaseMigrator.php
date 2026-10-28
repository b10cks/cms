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

    /**
     * The last table each create migration builds. An unrecorded migration
     * counts as applied only when its table exists; any other unrecorded
     * migration is left for `migrate` to run.
     */
    private const PROOF_TABLES = [
        '0000_00_00_000004_create_space_tables' => 'audit_logs',
        '0000_00_00_000006_create_asset_collections_tables' => 'asset_share_events',
        '0000_00_00_000009_create_field_plugins_table' => 'field_plugins',
        '0000_00_00_000010_create_content_serials_table' => 'content_serials',
        '0000_00_00_000017_create_translation_units_table' => 'translation_units',
        '0000_00_00_000018_create_ai_tables' => 'ai_glossary_suggestions',
    ];

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
     * Record migrations whose tables exist but which the ledger lacks, so
     * `migrate` does not fail creating them again. This can happen if the
     * migrations table was cleared. Recorded under the name `migrate` uses.
     */
    private function cleanupOrphanedMigrations(string $connectionName): void
    {
        try {
            if (!Schema::connection($connectionName)->hasTable('migrations')) {
                return; // No migrations table yet, nothing to clean up
            }

            $schema = Schema::connection($connectionName);

            foreach ($this->getPendingMigrations($connectionName) as $migrationName) {
                $table = self::PROOF_TABLES[$migrationName] ?? null;

                if ($table === null || !$schema->hasTable($table)) {
                    continue;
                }

                $nextBatch = (DB::connection($connectionName)->table('migrations')->max('batch') ?? 0) + 1;
                DB::connection($connectionName)->table('migrations')->insert([
                    'migration' => $migrationName,
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

            // `migrate` records the bare name; older cleanups wrote
            // "database/migrations/spaces/NAME". Either counts as applied.
            $appliedMigrations = DB::connection($connectionName)
                ->table('migrations')
                ->pluck('migration')
                ->map(fn ($m) => basename($m, '.php'))
                ->toArray();

            // Return migrations that are in files but not in the applied list
            return array_values(array_diff($migrationNames, $appliedMigrations));
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
        $config = ['database' => $databaseName];

        // Shared-profile connections address their tables through a prefix in
        // the main database; the migration must run with the same prefix or it
        // would create (and verify) unprefixed tables.
        if ($prefix = data_get($connection->config, 'prefix')) {
            $config['prefix'] = $prefix;
        }

        $admin = new SpaceConnection([
            'driver' => $connection->driver,
            'config' => $config,
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
