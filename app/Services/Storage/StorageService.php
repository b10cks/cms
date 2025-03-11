<?php

namespace App\Services\Storage;

use App\Models\Management\Space;
use App\Models\Management\Storage;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage as LaravelStorage;
use Throwable;

class StorageService
{
    public function __construct(
        private readonly StorageFactory $factory
    ) {
    }

    /**
     * Get the default storage for a space
     * 
     * @param Space $space
     * @return Filesystem
     * @throws StorageException
     */
    public function getDefaultStorage(Space $space): Filesystem
    {
        try {
            $storage = $space->storages()
                ->where('is_default', true)
                ->first();

            if (!$storage) {
                throw new StorageException("No default storage found for space: {$space->id}");
            }

            return $this->getStorage($storage);
        } catch (Throwable $e) {
            Log::error('Failed to establish storage connection', [
                'space_id' => $space->id,
                'error' => $e->getMessage(),
            ]);

            throw new StorageException(
                "Failed to establish storage connection: {$e->getMessage()}",
                previous: $e
            );
        }
    }

    /**
     * Get a storage filesystem instance
     * 
     * @param Storage $storage
     * @return Filesystem
     * @throws StorageException
     */
    public function getStorage(Storage $storage): Filesystem
    {
        return $this->factory->make($storage);
    }

    /**
     * Test if a storage connection is valid
     * 
     * @param Storage $storage
     * @return bool
     */
    public function testStorage(Storage $storage): bool
    {
        try {
            $filesystem = $this->factory->make($storage);
            // Attempt a simple operation to test connectivity
            $filesystem->exists('test-connection.txt');
            return true;
        } catch (Throwable $e) {
            Log::warning('Storage test failed', [
                'storage_id' => $storage->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Clear storage disk from the cache
     * 
     * @param Storage $storage
     * @return void
     */
    public function clearStorage(Storage $storage): void
    {
        LaravelStorage::forgetDisk($storage->id);
    }

    /**
     * Get driver capabilities
     * 
     * @param string $driver
     * @return array
     */
    public function getDriverCapabilities(string $driver): array
    {
        $capabilities = [
            'local' => [
                'public_urls' => true,
                'remote_access' => false,
                'versioning' => false,
                'direct_uploads' => true,
                'image_processing' => true,
            ],
            's3' => [
                'public_urls' => true,
                'remote_access' => true,
                'versioning' => true,
                'direct_uploads' => true,
                'image_processing' => true,
            ],
            'sftp' => [
                'public_urls' => false,
                'remote_access' => true,
                'versioning' => false,
                'direct_uploads' => false,
                'image_processing' => false,
            ]
        ];

        return $capabilities[$driver] ?? [
            'public_urls' => false,
            'remote_access' => false,
            'versioning' => false,
            'direct_uploads' => false,
            'image_processing' => false,
        ];
    }
}
