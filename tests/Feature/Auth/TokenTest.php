<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TokenTest extends TestCase
{
    use LazilyRefreshDatabase;

    #[Test]
    public function it_issues_tokens()
    {
        $user = User::factory()->create(['password' => 'secret']);
        $this->postJson('auth/v1/token', [
            'email' => $user->email,
            'password' => 'secret',
        ])
            ->assertOk()
            ->assertJsonStructure([
                                      'token_type',
                                      'expires_in',
                                      'access_token',
                                  ]);
    }

    #[Test]
    public function it_fails_to_issue_token_for_invalid_credentials()
    {
        $user = User::factory()->create(['password' => 'secret']);
        $this->postJson('auth/v1/token', [
            'email' => $user->email,
            'password' => 'foo',
        ])
            ->assertUnauthorized();
    }
}
