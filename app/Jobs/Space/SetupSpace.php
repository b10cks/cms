<?php

namespace App\Jobs\Space;

use App\Jobs\QueuedJob;
use App\Models\Management\Space;
use Log;

class SetupSpace extends QueuedJob
{
    public function __construct(
        public Space $space
    )
    {
    }

    protected function execute(): void
    {
        $this->createDefaultStorage($this->space);
        $this->createLocalDefaultConnection($this->space);

        $this->space->update(['state' => 'live']);
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

        SetupConnection::dispatch($connection);
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
            'config' => [
                'root' => storage_path("app/spaces/{$space->id}"),
            ],
            'settings' => [
                'max_file_size' => 100 * 1024 * 1024,
            ],
        ]);

        SetupStorage::dispatch($storage);
    }

    protected function handleFailure(\Exception $e): void
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
