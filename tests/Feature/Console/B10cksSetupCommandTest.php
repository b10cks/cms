<?php

namespace Tests\Feature\Console;

use App\Console\Commands\B10cksSetupCommand;
use App\Services\Setup\InstallProfileResolver;
use App\Services\Setup\InstallState;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class B10cksSetupCommandTest extends TestCase
{
    private string $statePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->statePath = storage_path('app/testing/setup/install-state.json');

        config([
            'setup.state_path' => $this->statePath,
            'setup.http_enabled_path' => storage_path('app/testing/setup/http-enabled'),
            'app.key' => 'base64:'.base64_encode(random_bytes(32)),
        ]);

        @unlink($this->statePath);
        @mkdir(dirname($this->statePath), 0755, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->statePath);
        @rmdir(dirname($this->statePath));

        parent::tearDown();
    }

    /**
     * @return B10cksSetupCommand&object{recordedCalls: array<int, array{command: string, arguments: array<string, mixed>}>}
     */
    private function makeCommand(string $profile): B10cksSetupCommand
    {
        $files = app(Filesystem::class);
        $installState = new InstallState($files);
        $resolver = new InstallProfileResolver($installState);

        return new class($files, $resolver, $installState, $profile) extends B10cksSetupCommand
        {
            /** @var array<int, array{command: string, arguments: array<string, mixed>}> */
            public array $recordedCalls = [];

            public function __construct(
                Filesystem $files,
                InstallProfileResolver $resolver,
                InstallState $installState,
                private readonly string $profileOption
            ) {
                parent::__construct($files, $resolver, $installState);
            }

            public function option($key = null): mixed
            {
                return $key === 'profile' ? $this->profileOption : null;
            }

            public function call($command, array $arguments = []): int
            {
                $this->recordedCalls[] = [
                    'command' => (string) $command,
                    'arguments' => $arguments,
                ];

                return self::SUCCESS;
            }

            public function info($string, $verbosity = null): void {}

            public function warn($string, $verbosity = null): void {}
        };
    }

    #[Test]
    public function self_hosted_shared_profile_seeds_unlimited_plan_and_writes_install_state(): void
    {
        config(['edition.edition' => 'self-hosted']);

        $command = $this->makeCommand('shared');

        $this->assertSame(0, $command->handle());
        $this->assertFileExists($this->statePath);

        $payload = json_decode((string) file_get_contents($this->statePath), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('shared', $payload['profile']);
        $this->assertSame(config('app.version'), $payload['app_version']);
        $this->assertNotEmpty($payload['installed_at']);
        $this->assertSame([
            'migrate',
            'db:seed',
            'plans:setup',
            'subscriptions:backfill-free',
            'storage:link',
        ], array_column($command->recordedCalls, 'command'));
        $this->assertSame(['--class' => 'DatabaseSeeder', '--force' => true], $command->recordedCalls[1]['arguments']);
        $this->assertSame(['--self-hosted' => true], $command->recordedCalls[2]['arguments']);
    }

    #[Test]
    public function saas_edition_seeds_default_plans(): void
    {
        config(['edition.edition' => 'saas']);

        $command = $this->makeCommand('standard');

        $this->assertSame(0, $command->handle());

        $plansCall = collect($command->recordedCalls)->firstWhere('command', 'plans:setup');
        $this->assertSame([], $plansCall['arguments']);

        $payload = json_decode((string) file_get_contents($this->statePath), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('standard', $payload['profile']);
    }
}
