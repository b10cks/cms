<?php

namespace Tests\Feature\Auth;

use App\Http\Middleware\PreventDuringImpersonation;
use App\Http\Resources\User\OwnUserResource;
use App\Models\User;
use App\Services\Auth\ImpersonationService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ImpersonationTest extends TestCase
{
    use DatabaseMigrations;

    protected User $impersonatedUser;
    protected User $superuser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->superuser = User::factory()->create(['is_root' => true]);
        $this->impersonatedUser = User::factory()->create();
    }

    #[Test]
    public function it_returns_anew_token_for_the_impersonated_user(): void
    {
        $guard = $this->app['auth']->guard('api');

        $response = $this->postJson(route('auth.impersonate.store'), ['userId' => $this->impersonatedUser->getRouteKey()], [
            'Authorization' => 'Bearer ' . $guard->tokenById($this->superuser->getRouteKey()),
        ])->assertOk();

        $token = $response->json()['access_token'];

        $jwt = $guard->setToken($token);
        $this->assertTrue($jwt->payload()->matches([
            'sub' => $this->impersonatedUser->getRouteKey(),
            'ruid' => $this->superuser->getRouteKey(),
        ]));
    }

    #[Test]
    public function it_impersonates_user(): void
    {
        auth()->setDefaultDriver('api');
        /** @var ImpersonationService $service */
        $service = $this->app->make(ImpersonationService::class);

        $this->getJson(route('mgmt.users.me.show'), [
            'Authorization' => 'Bearer ' . $service->impersonate($this->superuser, $this->impersonatedUser),
        ])->assertOk()
            ->assertJson([
                'data' => OwnUserResource::make($this->impersonatedUser)->resolve(),
            ]);
    }

    #[Test]
    public function it_prevents_impersonation(): void
    {
        $guard = $this->app['auth']->guard('api');
        $this->postJson(route('auth.impersonate.store'), ['userId' => $this->superuser->getRouteKey()], [
            'Authorization' => 'Bearer ' . $guard->tokenById($this->impersonatedUser->getRouteKey()),
        ])->assertForbidden();
    }

    #[Test]
    public function it_disallows_actions_with_middleware(): void
    {
        Route::get('/test', function () {
            return response(null, 204);
        })->middleware([PreventDuringImpersonation::class]);

        $this->getJson('/test')
            ->assertNoContent();

        $token = $this->app['auth']->guard('api')->claims([
            ImpersonationService::CLAIM_REAL_USER_ID => $this->superuser->getRouteKey(),
        ])->tokenById($this->impersonatedUser->getRouteKey());
        $this->getJson('/test', [
            'Authorization' => 'Bearer ' . $token
        ])->assertForbidden();
    }
}
