<?php

namespace Tests\Feature\Notifications;

use App\Models\User;
use App\Notifications\Space\MentionNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Covers the "in-app first, email only when needed" delivery rules shared by
 * every in-app notification through the DeliversInApp trait.
 */
class DeliversInAppTest extends TestCase
{
    use RefreshDatabase;

    private function notification(): MentionNotification
    {
        return new MentionNotification(
            ['id' => 'space-1', 'name' => 'Space'],
            ['id' => 'content-1', 'name' => 'Content'],
            ['id' => 'author-1', 'display_name' => 'Author'],
            null,
            null,
            'hello',
        );
    }

    #[Test]
    public function registered_users_get_in_app_and_mail_channels(): void
    {
        $user = User::factory()->create();

        $this->assertSame(['database', 'broadcast', 'mail'], $this->notification()->via($user));
    }

    #[Test]
    public function non_users_only_get_mail(): void
    {
        $this->assertSame(['mail'], $this->notification()->via(new AnonymousNotifiable));
    }

    #[Test]
    public function in_app_channels_are_instant_and_mail_is_deferred_for_users(): void
    {
        $user = User::factory()->create();
        $notification = $this->notification();

        $this->assertNull($notification->withDelay($user, 'database'));
        $this->assertNull($notification->withDelay($user, 'broadcast'));
        $this->assertNotNull($notification->withDelay($user, 'mail'));
    }

    #[Test]
    public function mail_to_non_users_is_sent_immediately(): void
    {
        $this->assertNull($this->notification()->withDelay(new AnonymousNotifiable, 'mail'));
    }

    #[Test]
    public function mail_is_skipped_when_the_user_already_read_it_in_app(): void
    {
        $user = User::factory()->create();
        $notification = $this->notification();
        $notification->id = (string) Str::uuid();

        // Mirrors the database channel having stored the notification first.
        $user->notifications()->create([
            'id' => $notification->id,
            'type' => 'comment.mention',
            'data' => [],
            'read_at' => null,
        ]);

        // Unread in-app -> the email fallback is still needed.
        $this->assertTrue($notification->shouldSend($user, 'mail'));

        DatabaseNotification::query()->find($notification->id)->markAsRead();

        // Seen in-app -> no email.
        $this->assertFalse($notification->shouldSend($user->fresh(), 'mail'));
    }

    #[Test]
    public function non_mail_channels_always_send(): void
    {
        $user = User::factory()->create();
        $notification = $this->notification();
        $notification->id = (string) Str::uuid();

        $this->assertTrue($notification->shouldSend($user, 'database'));
        $this->assertTrue($notification->shouldSend($user, 'broadcast'));
    }
}
