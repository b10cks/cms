<?php

namespace App\Jobs\Space;

use App\Jobs\QueuedJob;
use App\Models\Management\Space;
use App\Services\Ai\OpenRouterKeyManager;
use Carbon\Carbon;
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
        $this->provisionDemoAiKey($this->space);

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

    protected function provisionDemoAiKey(Space $space): void
    {
        // Check if OpenRouter is enabled and if demo key provisioning is enabled
        if (!config('ai.drivers.openrouter.enabled', false)) {
            Log::info('OpenRouter is not enabled, skipping demo AI key provisioning', [
                'space' => $space->id,
            ]);
            return;
        }

        // Check if demo key provisioning is enabled
        if (!config('ai.drivers.openrouter.provision_demo_keys', true)) {
            Log::info('Demo AI key provisioning is disabled, skipping', [
                'space' => $space->id,
            ]);
            return;
        }

        // Check if the space already has an active AI key
        if ($space->aiKeys()->active()->exists()) {
            Log::info('Space already has an active AI key, skipping demo AI key provisioning', [
                'space' => $space->id,
            ]);
            return;
        }

        try {
            $manager = new OpenRouterKeyManager();

            // Get configuration values
            $limit = (float) config('ai.drivers.openrouter.demo_key_limit', 2.0);
            $expiryDays = (int) config('ai.drivers.openrouter.demo_key_expiry_days', 30);

            // Provision a demo key with configured limit and expiry
            $key = $manager->provisionKey(
                space: $space,
                limit: $limit,
                limitReset: 'monthly',
                expiresAt: Carbon::now()->addDays($expiryDays)
            );

            Log::info('Demo AI key provisioned successfully', [
                'space' => $space->id,
                'key_id' => $key->id,
                'limit' => $limit,
                'expires_at' => $key->expires_at->toDateString(),
            ]);
        } catch (\Exception $e) {
            // Don't fail the entire setup if demo key provisioning fails
            Log::warning('Failed to provision demo AI key', [
                'space' => $space->id,
                'error' => $e->getMessage(),
            ]);
        }
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
