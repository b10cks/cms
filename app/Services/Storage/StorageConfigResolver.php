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
        $default = config("filesystems.disks.{$driver}") ?? [];

        return array_merge(
            $default,
            $config,
            [
                'driver' => $driver,
            ]
        );
    }
}
