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

    /**
     * The app version this installation was last set up or upgraded to, as
     * recorded on the storage volume. Null when never installed, or when the
     * state predates version tracking.
     */
    public function recordedVersion(): ?string
    {
        $version = $this->read()['app_version'] ?? null;

        return is_string($version) && $version !== '' ? $version : null;
    }

    /**
     * Stamp the running version into the existing state, leaving the profile
     * and original install timestamp alone.
     */
    public function recordVersion(string $version): void
    {
        $state = $this->read();

        if ($state === null) {
            throw new RuntimeException('Cannot record a version before b10cks is installed.');
        }

        $state['app_version'] = $version;
        $state['upgraded_at'] = now()->toIso8601String();

        $encoded = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new RuntimeException('Failed to encode install state.');
        }

        $this->files->put($this->path(), $encoded.PHP_EOL);
    }

    /**
     * Whether self-registration has been permanently closed on this install.
     */
    public function registrationClosed(): bool
    {
        return $this->files->exists(config('setup.registration_closed_path'));
    }

    /**
     * Record that this install has an account, so registration stays closed
     * without needing to ask the database again. Best-effort: a read-only
     * storage directory must not turn a registration attempt into a 500 — the
     * caller has already decided to refuse this request either way.
     */
    public function closeRegistration(): void
    {
        $path = config('setup.registration_closed_path');

        if ($this->files->exists($path)) {
            return;
        }

        try {
            $directory = dirname($path);

            if (! $this->files->isDirectory($directory)) {
                $this->files->makeDirectory($directory, 0755, true);
            }

            $this->files->put($path, now()->toIso8601String().PHP_EOL);
        } catch (\Throwable $e) {
            report($e);
        }
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
