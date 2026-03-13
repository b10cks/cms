<?php

namespace Tests\Feature\Mgmt;

use App\Models\Management\Invite;
use App\Models\Management\Space;
use App\Models\Management\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PublicInviteControllerTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_returns_expired_and_accepted_invites_as_resources_with_status(): void
    {
        $inviter = User::factory()->create();
        $team = Team::factory()->create();
        $space = Space::factory()->create(['team_id' => $team->id]);

        $expiredInvite = Invite::factory()->create([
            'space_id' => $space->id,
            'team_id' => null,
            'invited_by' => $inviter->id,
            'expires_at' => now()->subDay(),
            'accepted_at' => null,
        ]);

        $acceptedInvite = Invite::factory()->create([
            'space_id' => $space->id,
            'team_id' => null,
            'invited_by' => $inviter->id,
            'expires_at' => now()->addDay(),
            'accepted_at' => now()->subHour(),
        ]);

        $this->getJson(route('mgmt.invites.show', $expiredInvite))
            ->assertOk()
            ->assertJsonPath('data.id', $expiredInvite->id)
            ->assertJsonPath('data.status', 'expired');

        $this->getJson(route('mgmt.invites.show', $acceptedInvite))
            ->assertOk()
            ->assertJsonPath('data.id', $acceptedInvite->id)
            ->assertJsonPath('data.status', 'accepted');
    }
}
