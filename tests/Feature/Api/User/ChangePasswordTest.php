<?php

namespace Tests\Feature\Api\User;

use App\Events\User\PasswordChanged;
use Hash;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ChangePasswordTest extends TestCase
{
    use LazilyRefreshDatabase;

    #[Test]
    public function a_user_can_change_their_password()
    {
        Event::fake();
        $this->createAndActAs();
        $this->postJson(route('mgmt.users.me.password'), [
            'old_password' => 'password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])
            ->assertNoContent();

        $this->user->refresh();
        $this->assertTrue(Hash::check('new-password', $this->user->password));

        Event::assertDispatched(PasswordChanged::class);
    }

    #[Test]
    public function a_user_cant_change_their_password_with_providing_a_wrong()
    {
        $this->createAndActAs();
        $this->postJson(route('mgmt.users.me.password'), [
            'old_password' => 'wrong-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])
            ->assertForbidden();
    }
}
