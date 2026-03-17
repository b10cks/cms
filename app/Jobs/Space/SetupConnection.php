<?php

namespace App\Jobs\Space;

use App\Enums\ConnectionDriver;
use App\Jobs\QueuedJob;
use App\Models\Management\SpaceConnection;
use App\Services\Database\DatabaseConnectionException;
use App\Services\Database\DatabaseConnectionService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use PDO;

class SetupConnection extends QueuedJob
{
    private DatabaseConnectionService $spaceConnectionService;
    private const MIN_PASSWORD_LENGTH = 32;
    private const ALLOWED_USERNAME_CHARS = 'abcdefghijklmnopqrstuvwxyz0123456789';

    public function __construct(public SpaceConnection $spaceConnection)
    {
        $this->spaceConnectionService = app(DatabaseConnectionService::class);
    }

    protected function execute(): void
    {
        $databaseName = $this->getDatabaseName();
        $pdo = $this->getTempConnection();

        $credentials = $this->ensureSecureCredentials();

        $this->spaceConnection->config = array_merge(
            $this->spaceConnection->config ?? [],
            [
                'database' => $databaseName,
                'username' => $credentials['username'],
                'password' => $credentials['password'],
            ]
        );

        $this->createDatabase($pdo, $databaseName);
        $this->createDatabaseUser($pdo, $credentials, $databaseName);

        $this->spaceConnectionService->getConnection($this->spaceConnection);

        $this->migrateDatabase($this->spaceConnection->id);

        $this->spaceConnection->state = 'live';
        $this->spaceConnection->save();
    }

    protected function getDatabaseName(): string
    {
        return 'b10cks_' . $this->sanitizeDatabaseName($this->spaceConnection->space->id);
    }

    protected function sanitizeDatabaseName(string $name): string
    {
        // Remove any potentially dangerous characters
        return preg_replace('/[^a-zA-Z0-9_]/', '_', $name);
    }

    protected function ensureSecureCredentials(): array
    {
        $config = $this->spaceConnection->config;

        // If credentials exist, validate them
        if (isset($config['username']) && isset($config['password'])) {
            return [
                'username' => $config['username'],
                'password' => $config['password']
            ];
        }

        // Generate new secure credentials
        return [
            'username' => $this->generateSecureUsername(),
            'password' => $this->generateSecurePassword()
        ];
    }

    protected function generateSecureUsername(): string
    {
        return 'u_' . $this->sanitizeDatabaseName($this->spaceConnection->space->id);
    }

    protected function generateSecurePassword(): string
    {
        return Str::random(self::MIN_PASSWORD_LENGTH);
    }

    protected function getTempConnection(): PDO
    {
        $tempConfig = array_merge(
            $this->spaceConnection->config ?? [],
            ['database' => null]
        );

        $tempConnection = new SpaceConnection([
            'driver' => $this->spaceConnection->driver,
            'config' => $tempConfig
        ]);

        return $this->spaceConnectionService
            ->getConnection($tempConnection)
            ->getPdo();
    }

    protected function createDatabaseUser(PDO $pdo, array $credentials, string $databaseName): void
    {
        $username = $credentials['username'];
        $password = $credentials['password'];

        // Escape identifiers and values
        $escapedUsername = $this->escapeIdentifier($username);
        $escapedDatabase = $this->escapeIdentifier($databaseName);

        $sql = match ($this->spaceConnection->driver) {
            ConnectionDriver::MYSQL->value => [
                // For MySQL, we'll handle the password directly in the query since PDO doesn't support
                // parameter binding in certain DDL statements
                sprintf(
                    "CREATE USER IF NOT EXISTS %s@'%%' IDENTIFIED BY '%s'",
                    $escapedUsername,
                    str_replace("'", "\\'", $password)
                ),
                "GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, INDEX, ALTER, REFERENCES, CREATE TEMPORARY TABLES ON {$escapedDatabase}.* TO {$escapedUsername}@'%'",
                "FLUSH PRIVILEGES"
            ],
            ConnectionDriver::PGSQL->value => [
                // For PostgreSQL, we can use parameter binding
                ["CREATE USER {$escapedUsername} WITH PASSWORD :password", [':password' => $password]],
                ["GRANT CONNECT ON DATABASE {$escapedDatabase} TO {$escapedUsername}", []],
                ["GRANT USAGE ON SCHEMA public TO {$escapedUsername}", []],
                ["GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO {$escapedUsername}", []],
                ["ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO {$escapedUsername}", []]
            ],
            default => throw new DatabaseConnectionException(
                "User creation not supported for driver: {$this->spaceConnection->driver}"
            )
        };

        foreach ($sql as $statement) {
            if ($this->spaceConnection->driver === ConnectionDriver::PGSQL->value) {
                // PostgreSQL style with parameter binding
                [$query, $params] = $statement;
                $stmt = $pdo->prepare($query);
                $stmt->execute($params);
            } else {
                // MySQL style direct execution
                $pdo->exec($statement);
            }
        }
    }

    protected function createDatabase(PDO $pdo, string $databaseName): void
    {
        $escapedDatabase = $this->escapeIdentifier($databaseName);

        $sql = match ($this->spaceConnection->driver) {
            ConnectionDriver::MYSQL->value =>
            "CREATE DATABASE IF NOT EXISTS {$escapedDatabase}
                 CHARACTER SET utf8mb4
                 COLLATE utf8mb4_unicode_ci",

            ConnectionDriver::PGSQL->value =>
            "CREATE DATABASE {$escapedDatabase}
                 WITH ENCODING='UTF8'
                 LC_COLLATE='en_US.UTF-8'
                 LC_CTYPE='en_US.UTF-8'",

            default => throw new DatabaseConnectionException(
                "Database creation not supported for driver: {$this->spaceConnection->driver}"
            )
        };

        $pdo->exec($sql);
    }

    public function migrateDatabase(string $connectionName)
    {
        Artisan::call('migrate', [
            '--path' => 'database/migrations/spaces',
            '--force' => true,
            '--database' => $connectionName,
        ]);
    }

    protected function escapeIdentifier(string $identifier): string
    {
        // Remove any potentially dangerous characters
        $cleaned = preg_replace('/[^a-zA-Z0-9_]/', '', $identifier);

        return match ($this->spaceConnection->driver) {
            ConnectionDriver::MYSQL->value => "`{$cleaned}`",
            ConnectionDriver::PGSQL->value => "\"{$cleaned}\"",
            default => $cleaned
        };
    }

    protected function handleFailure(\Exception $e): void
    {
        \Log::error('Failed to setup space connection', [
            'spaceConnection' => $this->spaceConnection->id,
            'error' => $e->getMessage(),
        ]);

        $this->spaceConnection->update(['state' => 'error']);
    }

    public function tags(): array
    {
        return ['spaceConnection:' . $this->spaceConnection->id, 'setup'];
    }

    protected function prepareDatabase()
    {
        $schema = $this->spaceConnection->getConnection()->getSchemaBuilder();
        $schema->create('migrations', function ($table) {
            $table->increments('id');
            $table->string('migration');
            $table->integer('batch');
        });
    }
}
