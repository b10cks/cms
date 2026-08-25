<?php

namespace Tests\Feature\Mgmt;

use App\Models\Management\Space;
use App\Models\Management\Team;
use App\Models\User;
use App\Notifications\Management\InviteToSpaceNotification;
use App\Notifications\Management\InviteToTeamNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InviteLanguageTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_team_invite_is_stored_and_mailed_in_the_chosen_language(): void
    {
        Notification::fake();
        $owner = User::factory()->create();
        $team = Team::factory()->create();
        $this->assignTeamRole($team, $owner, 'owner');

        $this->actingAs($owner)
            ->postJson(route('mgmt.teams.invites.store', $team), [
                'email' => 'neu@example.com',
                'role' => 'member',
                'language' => 'de',
            ])
            ->assertCreated()
            ->assertJsonPath('data.language', 'de');

        Notification::assertSentOnDemand(
            InviteToTeamNotification::class,
            fn ($notification) => $notification->locale === 'de',
        );
    }

    #[Test]
    public function a_space_invite_falls_back_to_the_request_language(): void
    {
        Notification::fake();
        $owner = User::factory()->create();
        $space = Space::factory()->create();
        $this->assignSpaceRole($space, $owner, 'owner');

        $this->actingAs($owner)
            ->postJson(route('mgmt.spaces.invites.store', $space), [
                'email' => 'nouveau@example.com',
                'role' => 'member',
            ], ['Accept-Language' => 'fr'])
            ->assertCreated()
            ->assertJsonPath('data.language', 'fr');

        Notification::assertSentOnDemand(
            InviteToSpaceNotification::class,
            fn ($notification) => $notification->locale === 'fr',
        );
    }

    #[Test]
    public function an_unsupported_language_is_rejected(): void
    {
        Notification::fake();
        $owner = User::factory()->create();
        $team = Team::factory()->create();
        $this->assignTeamRole($team, $owner, 'owner');

        $this->actingAs($owner)
            ->postJson(route('mgmt.teams.invites.store', $team), [
                'email' => 'nope@example.com',
                'role' => 'member',
                'language' => 'klingon',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('language');
    }
}
