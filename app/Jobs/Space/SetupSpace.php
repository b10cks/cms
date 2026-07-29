<?php

namespace App\Jobs\Space;

use App\Actions\Space\CreateToken;
use App\Enums\InstallProfile;
use App\Jobs\QueuedJob;
use App\Models\Management\Space;
use App\Services\Setup\InstallProfileResolver;
use Log;

class SetupSpace extends QueuedJob
{
    public function __construct(
        public Space $space,
        public ?string $blueprintId = null
    ) {
    }

    protected function execute(): void
    {
        $this->createDefaultStorage($this->space);
        $this->createLocalDefaultConnection($this->space);
        $this->createDefaultToken($this->space);

        // OpenRouter keys are provisioned from the space's subscription/plan
        // (see SyncSpaceAiKey, dispatched when the subscription is created),
        // not unconditionally at space creation.

        $this->space->update(['state' => 'live']);
    }

    /**
     * A read-only delivery token so the space is fetchable the moment it exists —
     * onboarding hands it straight to the CLI. Tokens are stored in plaintext, so
     * this one stays retrievable via the tokens API instead of being shown once.
     */
    protected function createDefaultToken(Space $space)
    {
        if ($space->tokens()->exists()) {
            return;
        }

        app(CreateToken::class)->execute([
            'name' => 'Default',
            'abilities' => ['*:read'],
        ], $space, null);
    }

    protected function createLocalDefaultConnection(Space $space)
    {
        if ($space->connections()->where('is_default', true)->exists()) {
            return;
        }

        $default = config('database.default');
        $config = config("database.connections.{$default}");

        $profile = app(InstallProfileResolver::class)->resolve();

        $connection = $space->connections()->create(
            self::defaultConnectionAttributes($config, $profile, $space->id)
        );

        $this->dispatchSetupConnection($connection);
    }

    /**
     * The connection record for a space's own database, derived from the main
     * database config. Pure so both profiles are testable without a live
     * connection to the driver they describe.
     *
     * @param  array<string, mixed>  $config  the main database's connection config
     * @return array<string, mixed>
     */
    public static function defaultConnectionAttributes(array $config, InstallProfile $profile, string $spaceId): array
    {
        $attributes = [
            'name' => 'Internal',
            'driver' => $config['driver'],
            'is_default' => true,
        ];

        // The standard profile provisions a database (and user) per space, so
        // the job generates those itself — nothing to carry over here.
        if ($profile !== InstallProfile::SHARED) {
            return $attributes;
        }

        // The shared profile provisions without CREATE DATABASE/CREATE USER
        // privileges: sqlite gets one file per space, everything else lives in
        // the main database behind a per-space table prefix.
        //
        // A sqlite main database always gets its own file, whatever
        // B10CKS_SPACE_DB_DRIVER says. Prefixing it instead would point the
        // space connection at the *main* file, so `space:delete` would unlink
        // the entire installation and a space backup would copy every other
        // tenant (plus the users table) into a downloadable zip.
        if (config('setup.space_db_driver') === 'sqlite' || $config['driver'] === 'sqlite') {
            $attributes['driver'] = 'sqlite';
            $attributes['config'] = [
                'database' => storage_path("app/spaces/{$spaceId}/space.sqlite"),
            ];

            return $attributes;
        }

        // Same restriction b10cks:setup enforces at install time — backups can
        // only dump prefixed table lists on MySQL/MariaDB.
        if (! in_array($config['driver'], ['mysql', 'mariadb'], true)) {
            throw new \RuntimeException(sprintf(
                'The shared install profile does not support a "%s" main database.',
                $config['driver']
            ));
        }

        $attributes['config'] = [
            'database' => $config['database'],
            'prefix' => self::sharedTablePrefix($spaceId),
        ];

        return $attributes;
    }

    /**
     * Deterministic, short (11 chars) so even the longest space table names
     * stay well under MySQL's 64-character identifier limit.
     */
    public static function sharedTablePrefix(string $spaceId): string
    {
        return 'sp'.substr(md5($spaceId), 0, 8).'_';
    }

    protected function dispatchSetupConnection($connection): void
    {
        SetupConnection::dispatchSync($connection, $this->blueprintId);
    }

    protected function createDefaultStorage(Space $space)
    {
        if ($space->storages()->where('is_default', true)->exists()) {
            return;
        }


        $default = config('filesystems.default');
        $config = config("filesystems.disks.{$default}");

        $storage = $space->storages()->forceCreate([
            'name' => 'Default Storage',
            'slug' => 'default',
            'state' => 'draft',
            'driver' => $config['driver'],
            'icon' => 'hard-drive',
            'color' => '#4f46e5',
            'is_default' => true,
            'is_managed' => true,
            'config' => $config['driver'] === 'local' ? [
                'root' => storage_path("app/spaces/{$space->id}"),
            ] : $config,
            'settings' => [
                'max_file_size' => 100 * 1024 * 1024,
            ],
        ]);

        SetupStorage::dispatchSync($storage);
    }

    protected function handleFailure(\Throwable $e): void
    {
        Log::error('Failed to setup space', [
            'space' => $this->space->id,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);

        $this->space->update(['state' => 'error']);
    }

    public function tags(): array
    {
        return ['space:' . $this->space->id, 'setup'];
    }
}
