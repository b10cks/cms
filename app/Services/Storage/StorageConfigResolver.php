<?php

namespace App\Services\Storage;

use App\Models\Management\Storage;

class StorageConfigResolver
{
    /**
     * Resolve storage configuration
     * 
     * @param Storage $storage
     * @return array
     */
    public function resolve(Storage $storage): array
    {
        $config = $storage->config ?? [];
        $driver = $storage->driver;

        // Get default configuration for the driver
        $default = config("filesystems.disks.{$driver}") ?? [];

        // Merge with custom configuration
        $result = array_merge(
            $default,
            $config,
            [
                'driver' => $driver,
            ]
        );

        // Add space-specific path prefixes for organization when using
        // shared storage drivers (like local or s3)
        if (in_array($driver, ['local', 's3']) && !isset($config['prefix'])) {
            $result['prefix'] = "space-{$storage->space_id}";
        }

        return $result;
    }
}
