<?php

namespace Tests\Feature\Mgmt;

use App\Http\Controllers\Mgmt\SpaceController;
use App\Models\Management\Plan;
use App\Models\Management\Space;
use App\Models\Management\SpaceConnection;
use App\Models\Management\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\SpaceTestingTrait;

#[CoversClass(SpaceController::class)]
class SpaceControllerTest extends TestCase
{
    use LazilyRefreshDatabase;
    use SpaceTestingTrait;

    protected User $user;

    protected User $admin;

    protected User $owner;

    protected User $rootUser;

    protected Space $space;

    protected function setUp(): void
    {
        parent::setUp();

        // Create users with different roles
        $this->user = User::factory()->create();
        $this->admin = User::factory()->create();
        $this->owner = User::factory()->create();
        $this->rootUser = User::factory()->create(['is_root' => true]);

        // Create a test space
        $this->space = Space::factory()->withLive()->create();

        // Attach users to the space with different roles
        $this->assignSpaceRole($this->space, $this->user, 'member');
        $this->assignSpaceRole($this->space, $this->admin, 'admin');
        $this->assignSpaceRole($this->space, $this->owner, 'owner');

        $this->setUpSpaceTesting($this->space);
    }

    #[Test]
    public function user_can_view_spaces_list()
    {
        $this->actingAs($this->user);

        $spaces = Space::factory()->withLive()->count(3)->create();
        foreach ($spaces as $space) {
            $this->assignSpaceRole($space, $this->user, 'member');
        }

        $response = $this->getJson(route('mgmt.spaces.index'));

        $response->assertOk();
        $response->assertJsonCount(4, 'data'); // The original space + 3 new ones
        $response->assertJsonStructure([
            'data' => [
                '*' => [
                    'id', 'name', 'slug', 'icon', 'color', 'description',
                    'settings', 'user_count', 'created_at', 'updated_at',
                ],
            ],
            'links',
            'meta',
        ]);
    }

    //    #[Test]
    public function user_can_create_a_space()
    {
        Queue::fake();
        $this->actingAs($this->user);

        $spaceData = [
            'name' => 'Test Space',
            'slug' => 'test-space',
            'icon' => 'building',
            'color' => '#FF5733',
            'description' => 'A test space',
            'settings' => [
                'timezone' => 'UTC',
                'default_language' => 'en',
            ],
        ];

        $response = $this->postJson(route('mgmt.spaces.store'), $spaceData);

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'data' => [
                'id', 'name', 'slug', 'icon', 'color', 'description',
                'settings', 'created_at', 'updated_at',
            ],
        ]);

        $this->assertDatabaseHas('spaces', [
            'name' => 'Test Space',
            'slug' => 'test-space',
        ]);

        // Check if the user was attached to the space as an owner
        $spaceId = $response->json('data.id');
        $this->assertSpaceRole(Space::query()->findOrFail($spaceId), $this->user, 'owner');
    }

    #[Test]
    public function self_hosted_space_creation_without_plan_attaches_the_free_plan()
    {
        Queue::fake();
        config(['edition.edition' => 'self-hosted']);
        $this->actingAs($this->user);

        $plan = Plan::forceCreate([
            'name' => ['default' => 'Self-Hosted'],
            'price' => '0.00',
            'period' => 'month',
            'sort_order' => 10,
            'quotas' => null,
            'is_free' => true,
            'is_active' => true,
        ]);

        $response = $this->postJson(route('mgmt.spaces.store'), [
            'name' => 'Self-Hosted Space',
            'slug' => 'self-hosted-space',
        ]);

        $response->assertStatus(201);

        $subscription = Subscription::query()
            ->where('space_id', $response->json('data.id'))
            ->sole();

        $this->assertSame($plan->id, $subscription->plan_id);
        $this->assertTrue($subscription->isActive());
    }

    #[Test]
    public function user_cannot_create_a_space_with_invalid_data()
    {
        $this->actingAs($this->user);

        $response = $this->postJson(route('mgmt.spaces.store'), [
            // Missing required fields
            'icon' => 'building',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name', 'slug']);
    }

    //    #[Test]
    public function user_cannot_create_a_space_with_duplicate_slug()
    {
        $this->actingAs($this->user);

        $response = $this->postJson(route('mgmt.spaces.store'), [
            'name' => 'Another Test Space',
            'slug' => $this->space->slug, // Using existing slug
            'description' => 'Another test space',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['slug']);
    }

    #[Test]
    public function user_can_view_space_details()
    {
        $this->actingAs($this->user);

        $response = $this->getJson(route('mgmt.spaces.show', $this->space));

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                'id', 'name', 'slug', 'icon', 'color', 'description',
                'settings', 'user_count', 'created_at', 'updated_at',
            ],
        ]);
        $response->assertJson([
            'data' => [
                'id' => $this->space->id,
                'name' => $this->space->name,
                'slug' => $this->space->slug,
            ],
        ]);
    }

    #[Test]
    public function spaces_include_the_current_plan_summary()
    {
        $this->actingAs($this->user);

        $plan = Plan::query()->create([
            'name' => ['en' => 'Scale'],
            'description' => ['en' => 'Scale plan'],
            'features' => ['en' => ['Priority support']],
            'price' => '29.00',
            'period' => 'month',
            'quotas' => null,
            'is_free' => false,
            'is_active' => true,
            'sort_order' => 10,
        ]);

        Subscription::query()->create([
            'space_id' => $this->space->id,
            'plan_id' => $plan->id,
            'name' => 'Scale',
            'lemon_squeezy_id' => 'sub_active',
            'status' => 'active',
            'variant_id' => 'variant-active',
            'product_id' => 'product-active',
            'quantity' => 1,
        ]);

        Subscription::query()->create([
            'space_id' => $this->space->id,
            'plan_id' => $plan->id,
            'name' => 'Scale',
            'lemon_squeezy_id' => null,
            'status' => 'pending',
            'variant_id' => 'variant-pending',
            'product_id' => 'product-pending',
            'quantity' => 1,
        ]);

        $response = $this->getJson(route('mgmt.spaces.index'));

        $response->assertOk();
        $response->assertJsonPath('data.0.plan.name', 'Scale');
        $response->assertJsonPath('data.0.plan.status', 'active');
        $response->assertJsonPath('data.0.plan.id', $plan->id);
    }

    #[Test]
    public function unauthorized_user_cannot_view_space_details()
    {
        $anotherUser = User::factory()->create();
        $this->actingAs($anotherUser);

        $response = $this->getJson(route('mgmt.spaces.show', $this->space));

        $response->assertForbidden();
    }

    #[Test]
    public function admin_can_update_space()
    {
        $this->actingAs($this->admin);

        $updateData = [
            'name' => 'Updated Space Name',
            'description' => 'Updated description',
        ];

        $response = $this->putJson(route('mgmt.spaces.update', $this->space), $updateData);

        $response->assertOk();
        $response->assertJson([
            'data' => [
                'name' => 'Updated Space Name',
                'description' => 'Updated description',
            ],
        ]);

        $this->assertDatabaseHas('spaces', [
            'id' => $this->space->id,
            'name' => 'Updated Space Name',
            'description' => 'Updated description',
        ]);
    }

    #[Test]
    public function regular_member_cannot_update_space()
    {
        $this->actingAs($this->user);

        $updateData = [
            'name' => 'Updated Space Name',
            'description' => 'Updated description',
        ];

        $response = $this->putJson(route('mgmt.spaces.update', $this->space), $updateData);

        $response->assertForbidden();
    }

    #[Test]
    public function root_user_can_update_any_space()
    {
        $this->actingAs($this->rootUser);

        $updateData = [
            'name' => 'Root Updated Space',
            'description' => 'Updated by root user',
        ];

        $response = $this->putJson(route('mgmt.spaces.update', $this->space), $updateData);

        $response->assertOk();
        $response->assertJson([
            'data' => [
                'name' => 'Root Updated Space',
                'description' => 'Updated by root user',
            ],
        ]);
    }

    #[Test]
    public function update_space_with_invalid_data_fails()
    {
        $this->actingAs($this->admin);

        $updateData = [
            'color' => 'not-a-hex-color',
        ];

        $response = $this->putJson(route('mgmt.spaces.update', $this->space), $updateData);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['color']);
    }

    #[Test]
    public function owner_can_delete_space()
    {
        $this->actingAs($this->owner);

        SpaceConnection::where('space_id', $this->space->id)->delete();
        $response = $this->deleteJson(route('mgmt.spaces.destroy', $this->space));

        $response->assertStatus(204);
        $this->assertSoftDeleted('spaces', ['id' => $this->space->id]);
    }

    #[Test]
    public function admin_cannot_delete_space()
    {
        $this->actingAs($this->admin);

        SpaceConnection::where('space_id', $this->space->id)->delete();
        $response = $this->deleteJson(route('mgmt.spaces.destroy', $this->space));

        $response->assertForbidden();
    }

    #[Test]
    public function root_user_can_delete_any_space()
    {
        $this->actingAs($this->rootUser);

        SpaceConnection::where('space_id', $this->space->id)->delete();
        $response = $this->deleteJson(route('mgmt.spaces.destroy', $this->space));

        $response->assertStatus(204);
        $this->assertSoftDeleted('spaces', ['id' => $this->space->id]);
    }

    #[Test]
    public function cannot_delete_space_with_connections()
    {
        $this->actingAs($this->owner);

        // Create a connection for the space
        SpaceConnection::factory()->create(['space_id' => $this->space->id]);

        $response = $this->deleteJson(route('mgmt.spaces.destroy', $this->space));

        $response->assertStatus(422);
        $response->assertJson([
            'message' => 'Cannot delete a space that has connections. Please delete all connections first.',
        ]);

        $this->assertDatabaseHas('spaces', ['id' => $this->space->id]);
    }

    #[Test]
    public function spaces_can_be_filtered_by_name()
    {
        $this->actingAs($this->user);

        // Create spaces with specific names
        $spaceA = Space::factory()->withLive()->create(['name' => 'Space Alpha']);
        $spaceB = Space::factory()->withLive()->create(['name' => 'Space Beta']);
        $spaceC = Space::factory()->withLive()->create(['name' => 'Company Gamma']);

        // Attach all spaces to the user
        $this->assignSpaceRole($spaceA, $this->user, 'member');
        $this->assignSpaceRole($spaceB, $this->user, 'member');
        $this->assignSpaceRole($spaceC, $this->user, 'member');

        // Filter by "Space" in the name
        $response = $this->getJson(route('mgmt.spaces.index', ['name' => 'Space']));

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('data.0.name', 'Space Alpha');
        $response->assertJsonPath('data.1.name', 'Space Beta');
    }

    #[Test]
    public function spaces_can_be_sorted_by_name()
    {
        $this->actingAs($this->user);

        // Create spaces with specific names
        $spaceA = Space::factory()->withLive()->create(['name' => 'C Space']);
        $spaceB = Space::factory()->withLive()->create(['name' => 'A Space']);
        $spaceC = Space::factory()->withLive()->create(['name' => 'B Space']);

        // Attach all spaces to the user
        $this->assignSpaceRole($spaceA, $this->user, 'member');
        $this->assignSpaceRole($spaceB, $this->user, 'member');
        $this->assignSpaceRole($spaceC, $this->user, 'member');

        // Sort by name ascending
        $response = $this->getJson(route('mgmt.spaces.index', ['sort' => 'name']));

        $response->assertOk();
        $responseData = $response->json('data');

        // Extract just the space names we created for this test
        $spaceNames = collect($responseData)
            ->pluck('name')
            ->filter(fn ($name) => in_array($name, ['A Space', 'B Space', 'C Space']))
            ->values()
            ->all();

        $this->assertEquals(['A Space', 'B Space', 'C Space'], $spaceNames);

        // Sort by name descending
        $response = $this->getJson(route('mgmt.spaces.index', ['sort' => '-name']));

        $response->assertOk();
        $responseData = $response->json('data');

        // Extract just the space names we created for this test
        $spaceNames = collect($responseData)
            ->pluck('name')
            ->filter(fn ($name) => in_array($name, ['A Space', 'B Space', 'C Space']))
            ->values()
            ->all();

        $this->assertEquals(['C Space', 'B Space', 'A Space'], $spaceNames);
    }
}
