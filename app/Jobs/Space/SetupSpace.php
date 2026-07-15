<?php

namespace App\Jobs\Space;

use App\Actions\Space\CreateToken;
use App\Jobs\QueuedJob;
use App\Models\Management\Space;
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

        $connection = $space->connections()->create([
            'name' => 'Internal',
            'driver' => $config['driver'],
            'is_default' => true,
        ]);

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
