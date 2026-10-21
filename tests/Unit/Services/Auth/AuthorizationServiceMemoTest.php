<?php

namespace Tests\Unit\Services\Auth;

use App\Models\Management\Team;
use App\Models\User;
use App\Services\Auth\AuthorizationService;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AuthorizationServiceMemoTest extends TestCase
{
    use RefreshDatabase;

    private int $cacheReads = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Event::listen([CacheHit::class, CacheMissed::class], function (): void {
            $this->cacheReads++;
        });
    }

    #[Test]
    public function many_ability_checks_in_one_request_read_the_cache_once(): void
    {
        $user = User::factory()->create();
        $team = Team::factory()->create();
        $this->assignTeamRole($team, $user, 'owner');
        $authorization = app(AuthorizationService::class);
        $authorization->invalidateUser($user);
        $this->cacheReads = 0;

        foreach (range(1, 10) as $ignored) {
            $authorization->canInTeam($user, $team, 'team.view');
        }
        $authorization->accessibleSpaceIds($user);

        $this->assertSame(1, $this->cacheReads);
    }

    #[Test]
    public function invalidating_a_user_clears_the_memo(): void
    {
        $user = User::factory()->create();
        $team = Team::factory()->create();
        $authorization = app(AuthorizationService::class);

        $this->assertNotContains($team->id, $authorization->accessibleTeamIds($user));

        $this->assignTeamRole($team, $user, 'owner');

        $this->assertContains($team->id, $authorization->accessibleTeamIds($user));
    }

    #[Test]
    public function the_service_is_scoped_to_the_request(): void
    {
        $first = app(AuthorizationService::class);

        $this->assertSame($first, app(AuthorizationService::class));

        app()->forgetScopedInstances();

        $this->assertNotSame($first, app(AuthorizationService::class));
    }
}
