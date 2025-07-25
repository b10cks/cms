<?php

namespace App\Services\Storage;

use App\Models\Management\Storage;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage as LaravelStorage;

class StorageFactory
{
    public function __construct(
        private StorageConfigResolver $resolver
    ) {
    }

    /**
     * Make a filesystem instance for the given storage
     *
     * @param Storage $storage
     * @return Filesystem
     */
    public function make(Storage $storage): Filesystem
    {
        $config = $this->resolver->resolve($storage);

        // Set the configuration for this storage dynamically
        Config::set("filesystems.disks.{$storage->id}", $config);

        // Clear any existing disk with this name to ensure fresh config
        LaravelStorage::forgetDisk($storage->id);

        return LaravelStorage::disk($storage->id);
    }
}
