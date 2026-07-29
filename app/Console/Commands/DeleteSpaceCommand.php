<?php

namespace App\Console\Commands;

use App\Actions\Backup\DeleteBackup;
use App\Enums\ConnectionDriver;
use App\Models\Management\Space;
use App\Models\Management\SpaceBackup;
use App\Services\Database\DatabaseConnectionService;
use App\Services\Storage\StorageService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class DeleteSpaceCommand extends Command
{
    protected $signature = 'space:delete
        {space_id : The ID of the space to delete}
        {--force : Delete even if space state is live}
        {--skip-assets : Skip deletion of asset files from storage}';

    protected $description = 'Permanently delete a space including its database, files, and all relations';

    public function __construct(
        private readonly DatabaseConnectionService $connectionService,
        private readonly StorageService $storageService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $spaceId = $this->argument('space_id');

        $space = Space::withTrashed()->find($spaceId);

        if (!$space) {
            $this->error("Space with ID {$spaceId} not found.");
            return 1;
        }

        if ($space->state === 'live' && !$this->option('force')) {
            $this->error("Space \"{$space->name}\" ({$space->id}) is live. Use --force to delete anyway.");
            return 1;
        }

        $this->info("Deleting space: {$space->name} ({$space->id}) [state: {$space->state}]");

        if (!$this->confirm("This will permanently delete all data for this space. Are you sure?")) {
            $this->info('Aborted.');
            return 0;
        }

        if (!$this->option('skip-assets')) {
            $this->deleteAssetFiles($space);
        } else {
            $this->warn('Skipping asset file deletion (--skip-assets).');
        }

        $this->deleteSpaceDatabases($space);
        $this->deleteManagementRelations($space);

        $space->forceDelete();

        $this->info("Space \"{$space->name}\" ({$space->id}) has been permanently deleted.");
        return 0;
    }

    private function deleteAssetFiles(Space $space): void
    {
        $this->info('Deleting asset files from storage...');

        foreach ($space->storages()->withTrashed()->get() as $storage) {
            try {
                $filesystem = $this->storageService->getStorage($storage);
                $spaceDir = $space->id;

                if ($filesystem->directoryExists($spaceDir)) {
                    $filesystem->deleteDirectory($spaceDir);
                    $this->line("  Deleted storage directory for storage {$storage->id}");
                } else {
                    $this->line("  No directory found in storage {$storage->id}, skipping.");
                }
            } catch (Throwable $e) {
                $this->warn("  Failed to delete files from storage {$storage->id}: {$e->getMessage()}");
                Log::warning('DeleteSpaceCommand: failed to delete storage files', [
                    'space_id' => $space->id,
                    'storage_id' => $storage->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function deleteSpaceDatabases(Space $space): void
    {
        $this->info('Dropping space databases and DB users...');

        foreach ($space->connections()->withTrashed()->get() as $connection) {
            $driver = is_string($connection->driver) ? $connection->driver : $connection->driver->value;

            if (in_array($driver, [ConnectionDriver::SQLITE->value])) {
                // For SQLite: remove the file
                $dbPath = data_get($connection->config, 'database');
                if ($dbPath && file_exists($dbPath)) {
                    unlink($dbPath);
                    $this->line("  Deleted SQLite file: {$dbPath}");
                }
                continue;
            }

            $dbName = data_get($connection->config, 'database');
            $dbUser = data_get($connection->config, 'username');

            if (!$dbName) {
                $this->line("  No database name found for connection {$connection->id}, skipping.");
                continue;
            }

            // Shared-profile connections live in the main database behind a
            // table prefix — dropping the database would take the whole
            // installation with it. Drop only the prefixed tables.
            if ($prefix = data_get($connection->config, 'prefix')) {
                $this->dropPrefixedTables($connection, $prefix);
                continue;
            }

            try {
                $tempConfig = array_merge($connection->config ?? [], ['database' => null]);
                $tempConnection = new \App\Models\Management\SpaceConnection([
                    'driver' => $driver,
                    'config' => $tempConfig,
                ]);
                $pdo = $this->connectionService->getConnection($tempConnection)->getPdo();

                $this->dropDatabase($pdo, $driver, $dbName);

                if ($dbUser) {
                    $this->dropDatabaseUser($pdo, $driver, $dbUser);
                }

                $this->line("  Dropped database {$dbName}" . ($dbUser ? " and user {$dbUser}" : ''));
            } catch (Throwable $e) {
                $this->warn("  Failed to drop database for connection {$connection->id}: {$e->getMessage()}");
                Log::warning('DeleteSpaceCommand: failed to drop database', [
                    'space_id' => $space->id,
                    'connection_id' => $connection->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function dropPrefixedTables(\App\Models\Management\SpaceConnection $connection, string $prefix): void
    {
        try {
            $db = $this->connectionService->getConnection($connection);
            $pdo = $db->getPdo();
            $driver = is_string($connection->driver) ? $connection->driver : $connection->driver->value;

            $like = str_replace(['\\', '_', '%'], ['\\\\', '\\_', '\\%'], $prefix) . '%';
            $tables = match ($driver) {
                ConnectionDriver::MYSQL->value => array_column(
                    $pdo->query("SHOW TABLES LIKE " . $pdo->quote($like))->fetchAll(\PDO::FETCH_NUM),
                    0
                ),
                ConnectionDriver::PGSQL->value => array_column(
                    $pdo->query("SELECT tablename FROM pg_tables WHERE schemaname = 'public' AND tablename LIKE " . $pdo->quote($like))->fetchAll(\PDO::FETCH_NUM),
                    0
                ),
                default => [],
            };

            $isMysql = $driver === ConnectionDriver::MYSQL->value;

            if ($isMysql) {
                $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
            }
            foreach ($tables as $table) {
                $escaped = $this->escapeIdentifier($driver, $table);
                $pdo->exec($isMysql ? "DROP TABLE IF EXISTS {$escaped}" : "DROP TABLE IF EXISTS {$escaped} CASCADE");
            }
            if ($isMysql) {
                $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
            }

            $this->line('  Dropped ' . count($tables) . " prefixed tables ({$prefix}*)");
        } catch (Throwable $e) {
            $this->warn("  Failed to drop prefixed tables for connection {$connection->id}: {$e->getMessage()}");
            Log::warning('DeleteSpaceCommand: failed to drop prefixed tables', [
                'connection_id' => $connection->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function dropDatabase(\PDO $pdo, string $driver, string $dbName): void
    {
        $escaped = $this->escapeIdentifier($driver, $dbName);

        $sql = match ($driver) {
            ConnectionDriver::MYSQL->value => "DROP DATABASE IF EXISTS {$escaped}",
            ConnectionDriver::PGSQL->value => "DROP DATABASE IF EXISTS {$escaped}",
            default => throw new \RuntimeException("DROP DATABASE not supported for driver: {$driver}"),
        };

        $pdo->exec($sql);
    }

    private function dropDatabaseUser(\PDO $pdo, string $driver, string $username): void
    {
        $escaped = $this->escapeIdentifier($driver, $username);

        $sql = match ($driver) {
            ConnectionDriver::MYSQL->value => "DROP USER IF EXISTS {$escaped}@'%'",
            ConnectionDriver::PGSQL->value => "DROP USER IF EXISTS {$escaped}",
            default => null,
        };

        if ($sql) {
            $pdo->exec($sql);
        }
    }

    private function escapeIdentifier(string $driver, string $identifier): string
    {
        $cleaned = preg_replace('/[^a-zA-Z0-9_]/', '', $identifier);

        return match ($driver) {
            ConnectionDriver::MYSQL->value => "`{$cleaned}`",
            ConnectionDriver::PGSQL->value => "\"{$cleaned}\"",
            default => $cleaned,
        };
    }

    private function deleteManagementRelations(Space $space): void
    {
        $this->info('Removing management relations...');

        // Delete backups (with their S3 files)
        $deleteBackup = app(DeleteBackup::class);
        SpaceBackup::withTrashed()->where('space_id', $space->id)->each(function ($backup) use ($deleteBackup) {
            $deleteBackup->execute($backup);
        });

        // Tokens don't cascade from space, so delete manually (token_usage_stats + token_executions cascade from token)
        $space->tokens()->each(fn($token) => $token->delete());

        // Connections don't cascade from space - force delete them (DB already dropped above)
        $space->connections()->withTrashed()->each(fn($c) => $c->forceDelete());

        // These cascade on delete from space, but we force-delete to bypass SoftDeletes
        $space->storages()->withTrashed()->each(fn($s) => $s->forceDelete());
        $space->invites()->each(fn($i) => $i->delete());
        $space->subscriptions()->each(fn($s) => $s->delete());

        // AI configs and keys cascade from space (no SoftDeletes)
        $space->aiConfigs()->each(fn($c) => $c->delete());
        $space->aiKeys()->each(fn($k) => $k->delete());

        // Detach users from pivot table
        $space->users()->detach();

        $this->line('  Done.');
    }
}
