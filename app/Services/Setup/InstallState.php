<?php

namespace App\Services\Setup;

use App\Enums\InstallProfile;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;

class InstallState
{
    public function __construct(
        private readonly Filesystem $files
    ) {}

    public function path(): string
    {
        return config('setup.state_path');
    }

    public function httpEnabledPath(): string
    {
        return config('setup.http_enabled_path');
    }

    public function exists(): bool
    {
        return $this->files->exists($this->path());
    }

    public function read(): ?array
    {
        if (! $this->exists()) {
            return null;
        }

        $decoded = json_decode($this->files->get($this->path()), true);

        return is_array($decoded) ? $decoded : null;
    }

    public function write(InstallProfile $profile): void
    {
        $path = $this->path();
        $directory = dirname($path);

        if (! $this->files->isDirectory($directory)) {
            $this->files->makeDirectory($directory, 0755, true);
        }

        $payload = [
            'profile' => $profile->value,
            'installed_at' => now()->toIso8601String(),
            'app_version' => config('app.version'),
        ];

        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new RuntimeException('Failed to encode install state.');
        }

        $this->files->put($path, $encoded.PHP_EOL);
    }

    public function httpSetupEnabled(): bool
    {
        return filter_var(config('setup.http_enabled'), FILTER_VALIDATE_BOOL)
            || $this->httpEnabledMarkerExists();
    }

    public function httpEnabledMarkerExists(): bool
    {
        return $this->files->exists($this->httpEnabledPath());
    }

    public function deleteHttpEnabledMarker(): void
    {
        if ($this->httpEnabledMarkerExists()) {
            $this->files->delete($this->httpEnabledPath());
        }
    }
}
