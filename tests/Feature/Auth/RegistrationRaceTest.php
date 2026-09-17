<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RegistrationRaceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Two signups for one address can both pass the `unique` rule. The one that
     * loses the insert gets the rule's 422, not a 500.
     */
    #[Test]
    public function a_duplicate_email_that_slips_past_validation_is_a_422(): void
    {
        config(['edition.edition' => 'saas']);

        $competitor = User::factory()->make(['email' => 'race@example.com']);
        User::creating(function (User $user) use ($competitor): void {
            if ($user->email === $competitor->email && ! User::query()->where('email', $user->email)->exists()) {
                DB::table('users')->insert($competitor->getAttributes() + ['id' => $competitor->newUniqueId()]);
            }
        });

        $this->postJson(route('auth.register'), [
            'firstname' => 'Alex',
            'lastname' => 'Example',
            'email' => 'race@example.com',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email' => 'This email address is already registered.']);
    }
}
