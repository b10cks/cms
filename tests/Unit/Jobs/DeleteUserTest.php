<?php

namespace Tests\Unit\Jobs;

use App\Jobs\User\DeleteUser;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DeleteUserTest extends TestCase
{
    use LazilyRefreshDatabase;

    #[Test]
    public function itDeletesAUser()
    {
        Notification::fake();
        $user = User::factory()->create();

        (new DeleteUser($user))->handle();

        $this->assertSoftDeleted('users', [
            'firstname' => 'Anonymous',
            'lastname' => 'User',
            'email' => $user->email,
        ]);
        Notification::assertSentTimes(\App\Notifications\User\UserDeletedNotification::class, 1);
    }
}
