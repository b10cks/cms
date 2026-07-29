<?php

namespace Tests\Feature\Web;

use App\Enums\InstallProfile;
use App\Services\Setup\InstallState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SetupEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'setup.state_path' => storage_path('app/testing/setup/install-state.json'),
            'setup.http_enabled_path' => storage_path('app/testing/setup/http-enabled'),
            'setup.http_enabled' => false,
        ]);

        @unlink(config('setup.state_path'));
        @unlink(config('setup.http_enabled_path'));
        @mkdir(dirname(config('setup.state_path')), 0755, true);
    }

    protected function tearDown(): void
    {
        @unlink(config('setup.state_path'));
        @unlink(config('setup.http_enabled_path'));
        @rmdir(dirname(config('setup.state_path')));

        parent::tearDown();
    }

    #[Test]
    public function setup_returns_404_when_disabled(): void
    {
        $this->get('/setup')->assertNotFound();
    }

    #[Test]
    public function setup_runs_successfully_when_enabled_by_env(): void
    {
        config(['setup.http_enabled' => true]);

        Artisan::shouldReceive('call')
            ->once()
            ->with('b10cks:setup', ['--profile' => 'standard'])
            ->andReturn(0);
        Artisan::shouldReceive('output')
            ->once()
            ->andReturn("Setup complete\n");

        $this->get('/setup')
            ->assertOk()
            ->assertSee('b10cks setup completed')
            ->assertSee('Setup complete');
    }

    #[Test]
    public function setup_runs_successfully_when_enabled_by_magic_file_and_removes_the_file_afterward(): void
    {
        touch(config('setup.http_enabled_path'));

        Artisan::shouldReceive('call')
            ->once()
            ->with('b10cks:setup', ['--profile' => 'shared'])
            ->andReturn(0);
        Artisan::shouldReceive('output')
            ->once()
            ->andReturn("Shared profile installed\n");

        $this->get('/setup?profile=shared')
            ->assertOk()
            ->assertSee('Shared profile installed');

        $this->assertFileDoesNotExist(config('setup.http_enabled_path'));
    }

    #[Test]
    public function setup_refuses_rerun_after_install_state_exists(): void
    {
        config(['setup.http_enabled' => true]);
        app(InstallState::class)->write(InstallProfile::SHARED);

        Artisan::shouldReceive('call')->never();

        $this->get('/setup')
            ->assertOk()
            ->assertSee('already installed');
    }
}
