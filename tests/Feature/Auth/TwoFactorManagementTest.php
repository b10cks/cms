<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\Auth\ImpersonationService;
use App\Services\Auth\TwoFactorAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The endpoints that change which second factors work: enabling one, disabling
 * one, and rotating the backup codes. Each of them either hands out a factor or
 * takes one away, so a session alone must not be enough to reach them.
 */
class TwoFactorManagementTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    private function userWithTwoFactor(): User
    {
        $secret = app(TwoFactorAuthService::class)->generateSecret();

        return User::factory()->create([
            'password' => Hash::make(self::PASSWORD),
            'two_factor_secret' => $secret,
            'two_factor_enabled_at' => now(),
            'two_factor_backup_codes' => ['AAAAAAAA', 'BBBBBBBB'],
        ]);
    }

    #[Test]
    public function regenerating_backup_codes_requires_the_account_password(): void
    {
        $user = $this->userWithTwoFactor();

        $this->actingAs($user)
            ->postJson(route('auth.2fa.backup-codes.regenerate'))
            ->assertStatus(423)
            ->assertJsonPath('error_code', 'PASSWORD_CONFIRMATION_REQUIRED');

        $this->assertSame(
            ['AAAAAAAA', 'BBBBBBBB'],
            $user->fresh()->two_factor_backup_codes,
            'The codes were rotated without the password being proven.',
        );
    }

    #[Test]
    public function regenerating_backup_codes_succeeds_with_the_password(): void
    {
        $user = $this->userWithTwoFactor();

        $response = $this->actingAs($user)
            ->withHeader('x-password-confirmation', self::PASSWORD)
            ->postJson(route('auth.2fa.backup-codes.regenerate'))
            ->assertOk();

        $this->assertCount(8, $response->json('backup_codes'));
        $this->assertNotSame(['AAAAAAAA', 'BBBBBBBB'], $user->fresh()->two_factor_backup_codes);
    }

    #[Test]
    public function disabling_two_factor_requires_the_account_password(): void
    {
        $user = $this->userWithTwoFactor();

        $this->actingAs($user)
            ->postJson(route('auth.2fa.disable'))
            ->assertStatus(423);

        $this->assertTrue($user->fresh()->hasEnabledTwoFactor());
    }

    #[Test]
    public function disabling_two_factor_rejects_a_wrong_password(): void
    {
        $user = $this->userWithTwoFactor();

        $this->actingAs($user)
            ->withHeader('x-password-confirmation', 'not-the-password')
            ->postJson(route('auth.2fa.disable'))
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'INVALID_PASSWORD');

        $this->assertTrue($user->fresh()->hasEnabledTwoFactor());
    }

    #[Test]
    public function disabling_two_factor_succeeds_with_the_password(): void
    {
        $user = $this->userWithTwoFactor();

        $this->actingAs($user)
            ->withHeader('x-password-confirmation', self::PASSWORD)
            ->postJson(route('auth.2fa.disable'))
            ->assertOk();

        $this->assertFalse($user->fresh()->hasEnabledTwoFactor());
    }

    /**
     * Six digits with a ±1 window is three valid codes at any moment, and the
     * password behind the same header is guessable outright — so the failures
     * have to be counted rather than merely rate-limited per minute.
     */
    #[Test]
    public function repeated_password_failures_lock_the_step_up_check_out(): void
    {
        $user = $this->userWithTwoFactor();

        // The per-minute route limiter would answer 429 on its own and mask
        // whether the step-up counter works at all; this asserts the counter.
        $this->withoutMiddleware(ThrottleRequests::class);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->actingAs($user)
                ->withHeader('x-password-confirmation', 'wrong-'.$attempt)
                ->postJson(route('auth.2fa.disable'))
                ->assertStatus(403);
        }

        $this->actingAs($user)
            ->withHeader('x-password-confirmation', 'wrong-again')
            ->postJson(route('auth.2fa.disable'))
            ->assertStatus(429)
            ->assertJsonPath('error_code', 'TOO_MANY_ATTEMPTS');

        // The lockout has to survive the correct password too, otherwise the
        // attacker simply keeps guessing until one lands.
        $this->actingAs($user)
            ->withHeader('x-password-confirmation', self::PASSWORD)
            ->postJson(route('auth.2fa.disable'))
            ->assertStatus(429);

        $this->assertTrue($user->fresh()->hasEnabledTwoFactor());
    }

    #[Test]
    public function a_successful_verification_clears_the_failure_count(): void
    {
        $user = $this->userWithTwoFactor();

        $this->withoutMiddleware(ThrottleRequests::class);

        foreach (['wrong-1', 'wrong-2'] as $guess) {
            $this->actingAs($user)
                ->withHeader('x-password-confirmation', $guess)
                ->postJson(route('auth.2fa.backup-codes.regenerate'))
                ->assertStatus(403);
        }

        $this->actingAs($user)
            ->withHeader('x-password-confirmation', self::PASSWORD)
            ->postJson(route('auth.2fa.backup-codes.regenerate'))
            ->assertOk();

        for ($attempt = 0; $attempt < 4; $attempt++) {
            $this->actingAs($user)
                ->withHeader('x-password-confirmation', 'wrong-'.$attempt)
                ->postJson(route('auth.2fa.backup-codes.regenerate'))
                ->assertStatus(403);
        }
    }

    /**
     * An operator inside an impersonation session must not be able to walk away
     * with a second factor for the account they were inspecting.
     */
    #[Test]
    public function an_impersonator_cannot_rotate_backup_codes(): void
    {
        $root = User::factory()->create(['is_root' => true]);
        $target = $this->userWithTwoFactor();

        $this->actingAs($root);
        $token = $this->postJson(route('auth.impersonate.store'), ['userId' => $target->id])
            ->assertOk()
            ->json('access_token');

        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('x-password-confirmation', self::PASSWORD)
            ->postJson(route('auth.2fa.backup-codes.regenerate'))
            ->assertForbidden();

        $this->assertSame(['AAAAAAAA', 'BBBBBBBB'], $target->fresh()->two_factor_backup_codes);
    }

    #[Test]
    public function an_impersonator_cannot_disable_two_factor(): void
    {
        $root = User::factory()->create(['is_root' => true]);
        $target = $this->userWithTwoFactor();

        $this->actingAs($root);
        $token = $this->postJson(route('auth.impersonate.store'), ['userId' => $target->id])
            ->assertOk()
            ->json('access_token');

        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('x-password-confirmation', self::PASSWORD)
            ->postJson(route('auth.2fa.disable'))
            ->assertForbidden();

        $this->assertTrue($target->fresh()->hasEnabledTwoFactor());
    }

    #[Test]
    public function an_impersonator_cannot_enroll_a_second_factor(): void
    {
        $root = User::factory()->create(['is_root' => true]);
        $target = User::factory()->create(['password' => Hash::make(self::PASSWORD)]);

        $this->actingAs($root);
        $token = $this->postJson(route('auth.impersonate.store'), ['userId' => $target->id])
            ->assertOk()
            ->json('access_token');

        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson(route('auth.2fa.setup'))
            ->assertForbidden();
    }

    #[Test]
    public function enabling_two_factor_requires_the_account_password(): void
    {
        // Refreshed so the nullable two-factor columns are actually loaded:
        // a model straight out of create() only carries what was inserted.
        $user = User::factory()->create(['password' => Hash::make(self::PASSWORD)])->fresh();

        $secret = $this->actingAs($user)
            ->postJson(route('auth.2fa.setup'))
            ->assertOk()
            ->json('secret');

        $this->assertNotEmpty($secret);

        $this->actingAs($user)
            ->postJson(route('auth.2fa.setup.confirm'), ['code' => '123456'])
            ->assertStatus(423)
            ->assertJsonPath('error_code', 'PASSWORD_CONFIRMATION_REQUIRED');

        $this->assertFalse($user->fresh()->hasEnabledTwoFactor());
    }
}
