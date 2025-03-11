<?php

namespace App\Jobs\Space;

use App\Jobs\QueuedJob;
use App\Models\Management\Storage;
use App\Services\Storage\StorageService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SetupStorage extends QueuedJob
{
    public function __construct(
        public Storage $storage
    ) {
    }

    protected function execute(): void
    {
        $this->configureStorage();
        $this->storage->update(['state' => 'live']);
    }

    protected function configureStorage(): void
    {
        $config = $this->storage->config ?? [];

        // Generate any missing credentials or configuration based on driver
        switch ($this->storage->driver) {
            case 's3':
                $this->configureBucketIfManaged();
                break;

            case 'local':
                $this->ensureLocalPathExists();
                break;
        }

        // Save updated configuration
        if ($config !== $this->storage->config) {
            $this->storage->update(['config' => $config]);
        }
    }

    protected function configureBucketIfManaged(): void
    {
        // For managed S3 storage, we would typically:
        // 1. Create a new bucket or configure an existing one
        // 2. Set up proper IAM permissions
        // 3. Configure CORS, lifecycle policies, etc.

        // This is simplified for the example - in a real app you'd integrate with AWS SDK
        if ($this->storage->is_managed && empty($this->storage->config['bucket'])) {
            $bucketName = 'space-' . Str::slug($this->storage->space->slug) . '-' . Str::lower(Str::random(8));

            $this->storage->config = array_merge($this->storage->config ?? [], [
                'bucket' => $bucketName,
                'region' => $this->storage->config['region'] ?? 'us-east-1',
            ]);
        }
    }

    protected function ensureLocalPathExists(): void
    {
        // For local storage, ensure the directory exists
        if ($this->storage->driver === 'local') {
            $path = $this->storage->config['root'] ?? storage_path('app/spaces/' . $this->storage->space_id);

            if (!is_dir($path)) {
                mkdir($path, 0755, true);
            }

            // Update config with the path if it doesn't exist
            if (empty($this->storage->config['root'])) {
                $this->storage->config = array_merge($this->storage->config ?? [], [
                    'root' => $path,
                ]);
            }
        }
    }

    protected function createBasicStructure(): void
    {
        /** @var StorageService $storageService */
        $storageService = app(StorageService::class);
        $filesystem = $storageService->getStorage($this->storage);

        // Create basic directory structure
        $directories = [
            'assets',
            'assets/images',
            'assets/documents',
            'assets/videos',
            'assets/other',
            'temp',
        ];

        foreach ($directories as $directory) {
            if (!$filesystem->exists($directory)) {
                $filesystem->makeDirectory($directory);
            }
        }

        // Create a welcome file
        if (!$filesystem->exists('welcome.txt')) {
            $welcomeContent = "Storage initialized for Space: {$this->storage->space->name}\n";
            $welcomeContent .= "Created at: " . now()->toDateTimeString();

            $filesystem->put('welcome.txt', $welcomeContent);
        }
    }

    protected function handleFailure(\Exception $e): void
    {
        Log::error('Failed to setup storage', [
            'storage' => $this->storage->id,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);

        $this->storage->update(['state' => 'error']);
    }

    public function tags(): array
    {
        return ['storage:' . $this->storage->id, 'setup'];
    }
}
