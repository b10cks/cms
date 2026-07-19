<?php

namespace Tests\Feature\Mgmt;

use App\Models\Management\Invite;
use App\Models\Management\Plan;
use App\Models\Management\PlanProposal;
use App\Models\Management\Space;
use App\Models\Management\Subscription;
use App\Models\User;
use App\Notifications\Management\InviteToSpaceNotification;
use App\Notifications\Space\PaymentRequestedNotification;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PlanProposalTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected Space $space;

    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        Notification::fake();

        $this->space = Space::factory()->create();
        $this->owner = User::factory()->create();
        $this->assignSpaceRole($this->space, $this->owner, 'owner');
    }

    private function plan(array $overrides = []): Plan
    {
        return Plan::create(array_merge([
            'name' => ['en' => 'Pro', 'default' => 'Pro'],
            'price' => '29.00',
            'yearly_price' => '290.00',
            'period' => 'month',
            'quotas' => ['traffic' => 1000],
            'ls_product_id' => '9001',
            'ls_variant_id' => '1001',
            'ls_variant_id_yearly' => '2001',
            'is_free' => false,
            'is_public' => true,
            'is_active' => true,
            'sort_order' => 1,
        ], $overrides));
    }

    private function createProposal(Plan $plan, string $email = 'client@example.com', array $overrides = [])
    {
        $this->actingAs($this->owner);

        return $this->postJson(route('mgmt.spaces.subscriptions.proposal.store', $this->space), array_merge([
            'plan_id' => $plan->id,
            'interval' => 'month',
            'email' => $email,
        ], $overrides));
    }

    #[Test]
    public function a_proposal_for_a_non_member_creates_a_billing_invite(): void
    {
        $response = $this->createProposal($this->plan());

        $response->assertCreated();
        $response->assertJsonPath('data.status', 'open');
        $response->assertJsonPath('data.invited_email', 'client@example.com');

        $invite = Invite::where('space_id', $this->space->id)->first();
        $this->assertNotNull($invite);
        $this->assertSame('client@example.com', $invite->email);
        $this->assertSame('billing', $invite->roleDefinition->key);

        // Non-members are reached through the invitation mail.
        Notification::assertSentOnDemand(InviteToSpaceNotification::class);
        Notification::assertNothingSentTo($this->owner);
    }

    #[Test]
    public function a_proposal_for_an_existing_member_notifies_without_an_invite(): void
    {
        $client = User::factory()->create(['email' => 'client@example.com']);
        $this->assignSpaceRole($this->space, $client, 'billing');

        $this->createProposal($this->plan())->assertCreated();

        $this->assertSame(0, Invite::where('space_id', $this->space->id)->count());
        Notification::assertSentTo($client, PaymentRequestedNotification::class);
    }

    #[Test]
    public function a_new_proposal_supersedes_the_previous_one(): void
    {
        $plan = $this->plan();
        $this->createProposal($plan, 'first@example.com')->assertCreated();
        $this->createProposal($plan, 'second@example.com')->assertCreated();

        $statuses = PlanProposal::where('space_id', $this->space->id)
            ->orderBy('created_at')
            ->pluck('status', 'invited_email');

        $this->assertSame('revoked', $statuses['first@example.com']);
        $this->assertSame('open', $statuses['second@example.com']);
    }

    #[Test]
    public function free_and_contact_plans_cannot_be_proposed(): void
    {
        $free = $this->plan(['is_free' => true, 'price' => '0.00', 'sort_order' => 2]);
        $contact = $this->plan(['contact_url' => 'https://example.com/contact', 'sort_order' => 3]);

        $this->createProposal($free)->assertStatus(422);
        $this->createProposal($contact)->assertStatus(422);
    }

    #[Test]
    public function an_ungranted_custom_plan_cannot_be_proposed(): void
    {
        $custom = $this->plan(['is_public' => false, 'sort_order' => 4]);

        $this->createProposal($custom)->assertStatus(422);

        $custom->spaces()->attach($this->space->id);
        $this->createProposal($custom)->assertCreated();
    }

    #[Test]
    public function a_proposal_can_be_revoked(): void
    {
        $this->createProposal($this->plan())->assertCreated();

        $this->deleteJson(route('mgmt.spaces.subscriptions.proposal.destroy', $this->space))
            ->assertStatus(204);

        $this->getJson(route('mgmt.spaces.subscriptions.proposal.show', $this->space))
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    #[Test]
    public function an_expired_proposal_is_not_returned(): void
    {
        $this->createProposal($this->plan())->assertCreated();
        PlanProposal::query()->update(['expires_at' => now()->subDay()]);

        $this->actingAs($this->owner)
            ->getJson(route('mgmt.spaces.subscriptions.proposal.show', $this->space))
            ->assertOk()
            ->assertJsonPath('data', null);

        $this->assertSame('expired', PlanProposal::first()->status);
    }

    #[Test]
    public function a_paid_activation_resolves_the_open_proposal(): void
    {
        $plan = $this->plan();
        $this->createProposal($plan)->assertCreated();

        // The client completed the checkout: a paid subscription activates.
        Subscription::factory()->create([
            'space_id' => $this->space->id,
            'plan_id' => $plan->id,
            'status' => 'active',
        ]);

        $this->assertSame('accepted', PlanProposal::first()->status);
    }

    #[Test]
    public function a_free_plan_activation_does_not_resolve_the_proposal(): void
    {
        $free = $this->plan(['is_free' => true, 'price' => '0.00', 'sort_order' => 2]);
        $this->createProposal($this->plan())->assertCreated();

        Subscription::factory()->create([
            'space_id' => $this->space->id,
            'plan_id' => $free->id,
            'status' => 'active',
        ]);

        $this->assertSame('open', PlanProposal::first()->status);
    }

    #[Test]
    public function the_billing_role_can_manage_billing_but_nothing_else(): void
    {
        $client = User::factory()->create();
        $this->assignSpaceRole($this->space, $client, 'billing');
        $this->actingAs($client);

        $this->getJson(route('mgmt.spaces.subscriptions.proposal.show', $this->space))->assertOk();
        $this->getJson(route('mgmt.spaces.subscriptions.current', $this->space))->assertOk();

        // No membership or invite visibility.
        $this->getJson(route('mgmt.spaces.invites.index', $this->space))->assertForbidden();
    }

    #[Test]
    public function proposals_require_billing_management(): void
    {
        $viewer = User::factory()->create();
        $this->assignSpaceRole($this->space, $viewer, 'viewer');
        $this->actingAs($viewer);

        $this->postJson(route('mgmt.spaces.subscriptions.proposal.store', $this->space), [
            'plan_id' => $this->plan()->id,
            'email' => 'client@example.com',
        ])->assertForbidden();
    }
}
