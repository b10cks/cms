<?php

namespace Tests\Unit\Notifications;

use App\Models\Management\Invite;
use App\Models\Management\Role;
use App\Models\Management\Space;
use App\Models\Management\Team;
use App\Models\User;
use App\Notifications\Management\InviteToSpaceNotification;
use App\Notifications\Management\InviteToTeamNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InviteNotificationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function space_invite_notifications_link_to_the_shared_invite_page(): void
    {
        config()->set('app.frontend_url', 'https://app.example.com');

        $inviter = User::factory()->create();
        $team = Team::factory()->create();
        $space = Space::factory()->create(['team_id' => $team->id]);

        $invite = Invite::factory()->create([
            'space_id' => $space->id,
            'team_id' => null,
            'invited_by' => $inviter->id,
        ]);

        $message = (new InviteToSpaceNotification($invite, $space, $inviter))->toMail(new \stdClass);

        $this->assertSame(
            "https://app.example.com/invites/{$invite->id}?invite_token={$invite->token}",
            $message->actionUrl
        );
    }

    /**
     * Anyone can register and invite any address, so the inviter's name must
     * not turn mail sent from the platform's address into a phishing page.
     */
    #[Test]
    public function the_inviters_name_cannot_inject_markup_or_links_into_the_mail(): void
    {
        $inviter = User::factory()->create([
            'firstname' => '</strong><div class="phish">Session expired</div>',
            'lastname' => '[Sign in again](https://evil.example)',
        ]);
        $team = Team::factory()->create();
        $space = Space::factory()->create(['team_id' => $team->id]);

        $invite = Invite::factory()->create([
            'space_id' => $space->id,
            'team_id' => null,
            'invited_by' => $inviter->id,
        ]);

        $html = (string) (new InviteToSpaceNotification($invite, $space, $inviter))
            ->toMail($inviter)
            ->render();

        $this->assertStringNotContainsString('<div class="phish">', $html);
        $this->assertStringNotContainsString('href="https://evil.example"', $html);
        $this->assertStringContainsString('Sign in again', $html);
    }

    #[Test]
    public function team_invite_notifications_link_to_the_shared_invite_page(): void
    {
        config()->set('app.frontend_url', 'https://app.example.com');

        $inviter = User::factory()->create();
        $team = Team::factory()->create();

        $invite = Invite::factory()->create([
            'space_id' => null,
            'team_id' => $team->id,
            'invited_by' => $inviter->id,
            'role_id' => Role::query()
                ->whereNull('team_id')
                ->where('scope', 'team')
                ->where('key', 'member')
                ->value('id'),
        ]);

        $message = (new InviteToTeamNotification($invite, $team, $inviter))->toMail(new \stdClass);

        $this->assertSame(
            "https://app.example.com/invites/{$invite->id}?invite_token={$invite->token}",
            $message->actionUrl
        );
    }
}
