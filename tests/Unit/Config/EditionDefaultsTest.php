<?php

namespace Tests\Unit\Config;

use Tests\TestCase;

/**
 * The transfers disk and AI mode default from B10CKS_EDITION when their own
 * env vars are unset. Loads the config files directly under a controlled env.
 */
class EditionDefaultsTest extends TestCase
{
    private const KEYS = ['B10CKS_EDITION', 'TRANSFERS_DISK_DRIVER', 'AWS_TRANSFERS_BUCKET', 'AI_MODE'];

    /** @var array<string, string|false> */
    private array $saved = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::KEYS as $key) {
            $this->saved[$key] = getenv($key);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $key => $value) {
            $this->setEnv($key, $value === false ? null : $value);
        }

        parent::tearDown();
    }

    public function test_self_hosted_defaults_to_local_transfers_and_single_ai(): void
    {
        $this->withEnv(['B10CKS_EDITION' => 'self-hosted']);

        $transfers = $this->loadConfig('filesystems')['disks']['transfers'];

        $this->assertSame('local', $transfers['driver']);
        $this->assertSame(storage_path('app/transfers'), $transfers['root']);
        $this->assertSame('single', $this->loadConfig('ai')['mode']);
    }

    public function test_saas_defaults_to_s3_transfers_and_per_space_ai(): void
    {
        $this->withEnv(['B10CKS_EDITION' => null]);

        $transfers = $this->loadConfig('filesystems')['disks']['transfers'];

        $this->assertSame('s3', $transfers['driver']);
        $this->assertSame('', $transfers['root']);
        $this->assertSame('space', $this->loadConfig('ai')['mode']);
    }

    public function test_explicit_env_wins_over_edition(): void
    {
        $this->withEnv([
            'B10CKS_EDITION' => 'self-hosted',
            'TRANSFERS_DISK_DRIVER' => 's3',
            'AI_MODE' => 'space',
        ]);

        $transfers = $this->loadConfig('filesystems')['disks']['transfers'];

        $this->assertSame('s3', $transfers['driver']);
        $this->assertSame('', $transfers['root']);
        $this->assertSame('space', $this->loadConfig('ai')['mode']);
    }

    public function test_self_hosted_keeps_existing_transfers_on_s3_when_a_bucket_is_configured(): void
    {
        $this->withEnv([
            'B10CKS_EDITION' => 'self-hosted',
            'AWS_TRANSFERS_BUCKET' => 'existing-transfers',
        ]);

        $transfers = $this->loadConfig('filesystems')['disks']['transfers'];

        $this->assertSame('s3', $transfers['driver']);
        $this->assertSame('', $transfers['root']);
        $this->assertSame('existing-transfers', $transfers['bucket']);
    }

    public function test_explicit_local_driver_wins_over_a_configured_bucket(): void
    {
        $this->withEnv([
            'B10CKS_EDITION' => 'self-hosted',
            'AWS_TRANSFERS_BUCKET' => 'existing-transfers',
            'TRANSFERS_DISK_DRIVER' => 'local',
        ]);

        $transfers = $this->loadConfig('filesystems')['disks']['transfers'];

        $this->assertSame('local', $transfers['driver']);
        $this->assertSame(storage_path('app/transfers'), $transfers['root']);
    }

    /** @param array<string, string|null> $values Unlisted keys are unset. */
    private function withEnv(array $values): void
    {
        foreach (self::KEYS as $key) {
            $this->setEnv($key, $values[$key] ?? null);
        }
    }

    private function setEnv(string $key, ?string $value): void
    {
        if ($value === null) {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);

            return;
        }

        $_ENV[$key] = $_SERVER[$key] = $value;
        putenv("{$key}={$value}");
    }

    /** @return array<string, mixed> */
    private function loadConfig(string $name): array
    {
        return require config_path("{$name}.php");
    }
}
