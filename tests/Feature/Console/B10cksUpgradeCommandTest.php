<?php

namespace Tests\Feature\Console;

use App\Console\Commands\B10cksUpgradeCommand;
use App\Enums\InstallProfile;
use App\Services\Setup\InstallState;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class B10cksUpgradeCommandTest extends TestCase
{
    private string $statePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->statePath = storage_path('app/testing/upgrade/install-state.json');

        config(['setup.state_path' => $this->statePath]);

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
     * @return B10cksUpgradeCommand&object{recordedCalls: array<int, string>}
     */
    private function makeCommand(bool $force = false): B10cksUpgradeCommand
    {
        $installState = new InstallState(app(Filesystem::class));

        return new class($installState, $force) extends B10cksUpgradeCommand
        {
            /** @var array<int, string> */
            public array $recordedCalls = [];

            public function __construct(
                InstallState $installState,
                private readonly bool $forceOption
            ) {
                parent::__construct($installState);
            }

            public function option($key = null): mixed
            {
                return $key === 'force' ? $this->forceOption : null;
            }

            public function call($command, array $arguments = []): int
            {
                $this->recordedCalls[] = (string) $command;

                return self::SUCCESS;
            }

            public function info($string, $verbosity = null): void {}

            public function warn($string, $verbosity = null): void {}

            public function line($string, $style = null, $verbosity = null): void {}
        };
    }

    private function writeState(string $version): void
    {
        $installState = new InstallState(app(Filesystem::class));
        $installState->write(InstallProfile::STANDARD);
        $installState->recordVersion($version);
    }

    #[Test]
    public function a_version_change_migrates_management_and_space_databases(): void
    {
        config(['app.version' => '2026.8.0']);
        $this->writeState('2026.7.0');

        $command = $this->makeCommand();

        $this->assertSame(0, $command->handle());
        $this->assertSame(['migrate', 'spaces:repair-databases'], $command->recordedCalls);

        $payload = json_decode((string) file_get_contents($this->statePath), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('2026.8.0', $payload['app_version']);
        $this->assertNotEmpty($payload['upgraded_at']);
        // The original install metadata survives the stamp.
        $this->assertSame('standard', $payload['profile']);
    }

    #[Test]
    public function an_unchanged_version_is_a_no_op(): void
    {
        config(['app.version' => '2026.8.0']);
        $this->writeState('2026.8.0');

        $command = $this->makeCommand();

        $this->assertSame(0, $command->handle());
        $this->assertSame([], $command->recordedCalls);
    }

    #[Test]
    public function force_migrates_even_when_the_version_matches(): void
    {
        config(['app.version' => '2026.8.0']);
        $this->writeState('2026.8.0');

        $command = $this->makeCommand(force: true);

        $this->assertSame(0, $command->handle());
        $this->assertSame(['migrate', 'spaces:repair-databases'], $command->recordedCalls);
    }

    /**
     * The floating dev/compose tag cannot show that the image changed, so it
     * has to migrate every boot rather than never.
     */
    #[Test]
    public function the_latest_tag_always_migrates(): void
    {
        config(['app.version' => 'latest']);
        $this->writeState('latest');

        $command = $this->makeCommand();

        $this->assertSame(0, $command->handle());
        $this->assertSame(['migrate', 'spaces:repair-databases'], $command->recordedCalls);
    }

    #[Test]
    public function state_written_before_version_tracking_still_upgrades(): void
    {
        config(['app.version' => '2026.8.0']);

        // A state file with no app_version at all.
        file_put_contents($this->statePath, json_encode(['profile' => 'standard']));

        $command = $this->makeCommand();

        $this->assertSame(0, $command->handle());
        $this->assertSame(['migrate', 'spaces:repair-databases'], $command->recordedCalls);
    }

    #[Test]
    public function an_uninstalled_instance_does_nothing(): void
    {
        config(['app.version' => '2026.8.0']);

        $command = $this->makeCommand();

        $this->assertSame(0, $command->handle());
        $this->assertSame([], $command->recordedCalls);
        $this->assertFileDoesNotExist($this->statePath);
    }
}
