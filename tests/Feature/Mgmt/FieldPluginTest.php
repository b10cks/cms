<?php

namespace Tests\Feature\Mgmt;

use App\Enums\RoleScope;
use App\Models\Management\Role;
use App\Models\Management\Space;
use App\Models\Space\Block;
use App\Models\Space\FieldPlugin;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\SpaceTestingTrait;

class FieldPluginTest extends TestCase
{
    use LazilyRefreshDatabase;
    use SpaceTestingTrait;

    protected User $owner;

    protected User $editor;

    protected User $viewer;

    protected Space $space;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->editor = User::factory()->create();
        $this->viewer = User::factory()->create();

        $this->space = Space::factory()->create();
        $this->assignSpaceRole($this->space, $this->owner, 'owner');
        $this->assignSpaceRole($this->space, $this->editor, 'editor');
        $this->assignSpaceRole($this->space, $this->viewer, 'viewer');

        $this->setUpSpaceTesting($this->space);
    }

    #[Test]
    public function owner_can_create_field_plugin(): void
    {
        $this->actingAs($this->owner);

        $response = $this->postJson(route('mgmt.field-plugins.store', $this->space->id), [
            'name' => 'Product Picker',
            'handle' => 'product-picker',
            'description' => 'Pick products from the shop',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.name', 'Product Picker');
        $response->assertJsonPath('data.handle', 'product-picker');
        $response->assertJsonPath('data.status', 'draft');
        $response->assertJsonPath('data.sandbox_url', null);

        $this->assertDatabaseHas('field_plugins', ['handle' => 'product-picker']);
    }

    #[Test]
    public function creating_with_code_publishes_the_bundle(): void
    {
        $this->actingAs($this->owner);

        $code = 'window.b10cksFieldPlugin={mount(){}}';

        $response = $this->postJson(route('mgmt.field-plugins.store', $this->space->id), [
            'name' => 'Published Plugin',
            'handle' => 'published-plugin',
            'code' => $code,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.status', 'published');
        $response->assertJsonPath('data.code_hash', hash('sha256', $code));
        $response->assertJsonPath('data.code_size', strlen($code));
        $this->assertNotNull($response->json('data.sandbox_url'));
        $this->assertNotNull($response->json('data.published_at'));
    }

    #[Test]
    public function handle_must_be_unique_and_slug_shaped(): void
    {
        $this->actingAs($this->owner);

        FieldPlugin::withoutEvents(fn () => FieldPlugin::factory()->create(['handle' => 'taken']));

        $this->postJson(route('mgmt.field-plugins.store', $this->space->id), [
            'name' => 'Duplicate',
            'handle' => 'taken',
        ])->assertStatus(422)->assertJsonValidationErrors(['handle']);

        $this->postJson(route('mgmt.field-plugins.store', $this->space->id), [
            'name' => 'Bad Handle',
            'handle' => 'Not A Slug!',
        ])->assertStatus(422)->assertJsonValidationErrors(['handle']);
    }

    #[Test]
    public function handle_cannot_be_changed_on_update(): void
    {
        $this->actingAs($this->owner);

        $plugin = FieldPlugin::withoutEvents(fn () => FieldPlugin::factory()->create(['handle' => 'stable-handle']));

        $response = $this->patchJson(
            route('mgmt.field-plugins.update', [$this->space->id, $plugin->id]),
            ['name' => 'Renamed', 'handle' => 'new-handle']
        );

        $response->assertStatus(200);
        $response->assertJsonPath('data.name', 'Renamed');
        $response->assertJsonPath('data.handle', 'stable-handle');
    }

    #[Test]
    public function code_size_is_limited(): void
    {
        $this->actingAs($this->owner);

        $response = $this->postJson(route('mgmt.field-plugins.store', $this->space->id), [
            'name' => 'Huge Plugin',
            'handle' => 'huge-plugin',
            'code' => str_repeat('a', 1572865),
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['code']);
    }

    #[Test]
    public function dev_mode_plugin_reports_dev_status(): void
    {
        $this->actingAs($this->owner);

        $response = $this->postJson(route('mgmt.field-plugins.store', $this->space->id), [
            'name' => 'Dev Plugin',
            'handle' => 'dev-plugin',
            'dev_mode' => true,
            'dev_url' => 'http://localhost:5173/plugin',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.status', 'dev');
    }

    #[Test]
    public function listing_does_not_leak_bundle_code(): void
    {
        $this->actingAs($this->owner);

        FieldPlugin::withoutEvents(fn () => FieldPlugin::factory()->published()->create(['handle' => 'listed']));

        $response = $this->getJson(route('mgmt.field-plugins.index', $this->space->id));

        $response->assertStatus(200);
        $this->assertArrayNotHasKey('code', $response->json('data.0'));

        $plugin = FieldPlugin::query()->where('handle', 'listed')->firstOrFail();
        $show = $this->getJson(route('mgmt.field-plugins.show', [$this->space->id, $plugin->id]));
        $show->assertStatus(200);
        $this->assertNotNull($show->json('data.code'));
    }

    #[Test]
    public function editor_cannot_manage_but_can_view(): void
    {
        $plugin = FieldPlugin::withoutEvents(fn () => FieldPlugin::factory()->create(['handle' => 'view-only']));

        $this->actingAs($this->editor);

        $this->getJson(route('mgmt.field-plugins.index', $this->space->id))->assertStatus(200);
        $this->getJson(route('mgmt.field-plugins.show', [$this->space->id, $plugin->id]))->assertStatus(200);

        $this->postJson(route('mgmt.field-plugins.store', $this->space->id), [
            'name' => 'Nope',
            'handle' => 'nope',
        ])->assertStatus(403);

        $this->patchJson(
            route('mgmt.field-plugins.update', [$this->space->id, $plugin->id]),
            ['name' => 'Nope']
        )->assertStatus(403);

        $this->deleteJson(
            route('mgmt.field-plugins.destroy', [$this->space->id, $plugin->id])
        )->assertStatus(403);
    }

    #[Test]
    public function listing_requires_field_plugins_view_ability(): void
    {
        Role::query()->create([
            'team_id' => $this->space->team_id,
            'scope' => RoleScope::SPACE,
            'key' => 'no-plugins',
            'name' => 'No Plugins',
            'level' => 100,
            'abilities' => ['space.view', 'content.view'],
        ]);

        $user = User::factory()->create();
        $this->assignSpaceRole($this->space, $user, 'no-plugins');
        $this->actingAs($user);

        $this->getJson(route('mgmt.field-plugins.index', $this->space->id))->assertStatus(403);
    }

    #[Test]
    public function is_active_filter_casts_boolean_strings(): void
    {
        $this->actingAs($this->owner);

        FieldPlugin::withoutEvents(function () {
            FieldPlugin::factory()->create(['handle' => 'active-plugin', 'is_active' => true]);
            FieldPlugin::factory()->create(['handle' => 'inactive-plugin', 'is_active' => false]);
        });

        $active = $this->getJson(route('mgmt.field-plugins.index', [$this->space->id, 'is_active' => 'true']));
        $active->assertStatus(200);
        $this->assertSame(['active-plugin'], $active->json('data.*.handle'));

        $inactive = $this->getJson(route('mgmt.field-plugins.index', [$this->space->id, 'is_active' => 'false']));
        $inactive->assertStatus(200);
        $this->assertSame(['inactive-plugin'], $inactive->json('data.*.handle'));
    }

    #[Test]
    public function owner_can_delete_field_plugin(): void
    {
        $this->actingAs($this->owner);

        $plugin = FieldPlugin::withoutEvents(fn () => FieldPlugin::factory()->create(['handle' => 'doomed']));

        $this->deleteJson(
            route('mgmt.field-plugins.destroy', [$this->space->id, $plugin->id])
        )->assertStatus(204);

        $this->assertDatabaseMissing('field_plugins', ['id' => $plugin->id]);
    }

    #[Test]
    public function plugin_referenced_by_block_schema_cannot_be_deleted(): void
    {
        $this->actingAs($this->owner);

        $plugin = FieldPlugin::withoutEvents(fn () => FieldPlugin::factory()->create(['handle' => 'in-use']));
        Block::factory()->create([
            'schema' => [
                'product' => ['type' => 'plugin', 'name' => 'Product', 'plugin_handle' => 'in-use'],
            ],
        ]);

        $this->deleteJson(
            route('mgmt.field-plugins.destroy', [$this->space->id, $plugin->id])
        )->assertStatus(409);

        $this->assertDatabaseHas('field_plugins', ['id' => $plugin->id]);
    }
}
