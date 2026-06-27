<?php

namespace Tests\Feature\Mgmt;

use App\Http\Controllers\Mgmt\NotificationController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(NotificationController::class)]
class NotificationControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected User $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->other = User::factory()->create();
        Sanctum::actingAs($this->user);
    }

    private function makeNotification(User $user, ?string $readAt = null, string $type = 'comment.mention'): string
    {
        $id = (string) Str::uuid();

        $user->notifications()->create([
            'id' => $id,
            'type' => $type,
            'data' => ['space' => ['id' => 's', 'name' => 'Space']],
            'read_at' => $readAt,
        ]);

        return $id;
    }

    #[Test]
    public function it_lists_only_the_authenticated_users_notifications(): void
    {
        $this->makeNotification($this->user);
        $this->makeNotification($this->user);
        $this->makeNotification($this->other);

        $response = $this->getJson('/mgmt/v1/users/me/notifications');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
    }

    #[Test]
    public function it_can_filter_unread_only(): void
    {
        $this->makeNotification($this->user);
        $this->makeNotification($this->user, now()->toDateTimeString());

        $response = $this->getJson('/mgmt/v1/users/me/notifications?unread_only=1');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
    }

    #[Test]
    public function it_returns_the_unread_count(): void
    {
        $this->makeNotification($this->user);
        $this->makeNotification($this->user);
        $this->makeNotification($this->user, now()->toDateTimeString());

        $response = $this->getJson('/mgmt/v1/users/me/notifications/unread-count');

        $response->assertOk();
        $response->assertJsonPath('count', 2);
    }

    #[Test]
    public function it_marks_a_notification_as_read(): void
    {
        $id = $this->makeNotification($this->user);

        $response = $this->patchJson("/mgmt/v1/users/me/notifications/{$id}/read");

        $response->assertOk();
        $this->assertNotNull($this->user->notifications()->find($id)->read_at);
    }

    #[Test]
    public function it_marks_all_notifications_as_read(): void
    {
        $this->makeNotification($this->user);
        $this->makeNotification($this->user);

        $response = $this->postJson('/mgmt/v1/users/me/notifications/read');

        $response->assertNoContent();
        $this->assertSame(0, $this->user->unreadNotifications()->count());
    }

    #[Test]
    public function it_deletes_a_notification(): void
    {
        $id = $this->makeNotification($this->user);

        $response = $this->deleteJson("/mgmt/v1/users/me/notifications/{$id}");

        $response->assertNoContent();
        $this->assertNull($this->user->notifications()->find($id));
    }

    #[Test]
    public function it_cannot_touch_another_users_notification(): void
    {
        $id = $this->makeNotification($this->other);

        $this->patchJson("/mgmt/v1/users/me/notifications/{$id}/read")->assertNotFound();
        $this->deleteJson("/mgmt/v1/users/me/notifications/{$id}")->assertNotFound();

        // The other user's notification is untouched.
        $this->assertNotNull($this->other->notifications()->find($id));
    }
}
