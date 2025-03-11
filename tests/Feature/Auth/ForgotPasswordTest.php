<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Password;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ForgotPasswordTest extends TestCase
{
    use LazilyRefreshDatabase;

    #[Test]
    public function it_sends_password_reset_notification()
    {
        $user = User::factory()->create();
        $this->postJson(route('auth.password.email'), [
            'email' => $user->email,
        ])->assertSuccessful();
    }

    #[Test]
    public function it_resets_the_password()
    {
        $user = User::factory()->create();
        $token = Password::broker()->createToken($user);

        $this->postJson(route('auth.password.reset'), [
            'email' => $user->email,
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
            'token' => $token,
        ])->assertSuccessful();
    }
}
