<?php

namespace Tests\Feature\Mgmt;

use App\Http\Controllers\Mgmt\IconController;
use App\Models\Management\Space;
use App\Models\Space\Icon;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\SpaceTestingTrait;

#[CoversClass(IconController::class)]
class IconTest extends TestCase
{
    use RefreshDatabase;
    use SpaceTestingTrait;
    use WithFaker;

    protected User $user;

    protected Space $space;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->space = Space::factory()->create();
        $this->assignSpaceRole($this->space, $this->user, 'owner');

        Sanctum::actingAs($this->user);

        $this->setUpSpaceTesting($this->space);
        app()->instance('currentSpace', $this->space);
    }

    private function svgFile(string $name, string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $content);
    }

    #[Test]
    public function user_can_upload_an_icon_and_key_name_are_derived_from_filename(): void
    {
        $svg = '<svg viewBox="0 0 32 32"><path d="M1 1h30v30H1z"/></svg>';

        $response = $this->post(
            "/mgmt/v1/spaces/{$this->space->id}/icons",
            ['file' => $this->svgFile('Home Outline.svg', $svg)],
            ['Accept' => 'application/json']
        );

        $response->assertCreated();
        $response->assertJsonPath('data.key', 'home-outline');
        $response->assertJsonPath('data.name', 'Home Outline');
        $response->assertJsonPath('data.width', 32);
        $response->assertJsonPath('data.height', 32);

        $icon = Icon::query()->firstOrFail();
        $this->assertStringContainsString('<path', $icon->body);
        $this->assertStringNotContainsString('<svg', $icon->body);
    }

    #[Test]
    public function upload_sanitizes_scripts_and_event_handlers(): void
    {
        $svg = '<svg viewBox="0 0 24 24">'
            . '<script>alert(1)</script>'
            . '<path d="M0 0h24v24H0z" onload="alert(2)"/>'
            . '</svg>';

        $response = $this->post(
            "/mgmt/v1/spaces/{$this->space->id}/icons",
            ['file' => $this->svgFile('danger.svg', $svg), 'key' => 'danger', 'name' => 'Danger'],
            ['Accept' => 'application/json']
        );

        $response->assertCreated();

        $icon = Icon::query()->firstOrFail();
        $this->assertStringNotContainsString('<script', $icon->body);
        $this->assertStringNotContainsString('onload', $icon->body);
        $this->assertStringContainsString('<path', $icon->body);
    }

    #[Test]
    public function key_must_be_unique(): void
    {
        Icon::factory()->create(['key' => 'star']);

        $response = $this->post(
            "/mgmt/v1/spaces/{$this->space->id}/icons",
            [
                'file' => $this->svgFile('star.svg', '<svg viewBox="0 0 24 24"><path d="M1 1"/></svg>'),
                'key' => 'star',
                'name' => 'Star',
            ],
            ['Accept' => 'application/json']
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('key');
    }

    #[Test]
    public function invalid_svg_is_rejected(): void
    {
        $response = $this->post(
            "/mgmt/v1/spaces/{$this->space->id}/icons",
            [
                'file' => $this->svgFile('not-svg.svg', 'just some text, not svg'),
                'key' => 'broken',
                'name' => 'Broken',
            ],
            ['Accept' => 'application/json']
        );

        $response->assertStatus(422);
    }

    #[Test]
    public function user_can_list_search_and_filter_icons_by_tag(): void
    {
        Icon::factory()->create(['key' => 'arrow-left', 'name' => 'Arrow Left', 'tags' => ['navigation']]);
        Icon::factory()->create(['key' => 'arrow-right', 'name' => 'Arrow Right', 'tags' => ['navigation']]);
        Icon::factory()->create(['key' => 'heart', 'name' => 'Heart', 'tags' => ['social']]);

        $this->getJson("/mgmt/v1/spaces/{$this->space->id}/icons")
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure(['data' => [['id', 'key', 'name', 'body', 'width', 'height', 'tags']], 'meta']);

        $this->getJson("/mgmt/v1/spaces/{$this->space->id}/icons?q=arrow")
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->getJson("/mgmt/v1/spaces/{$this->space->id}/icons?tags[]=social")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.key', 'heart');
    }

    #[Test]
    public function tags_endpoint_returns_distinct_sorted_tags(): void
    {
        Icon::factory()->create(['key' => 'a', 'tags' => ['b', 'a']]);
        Icon::factory()->create(['key' => 'c', 'tags' => ['a', 'c']]);

        $this->getJson("/mgmt/v1/spaces/{$this->space->id}/icons/tags")
            ->assertOk()
            ->assertExactJson(['data' => ['a', 'b', 'c']]);
    }

    #[Test]
    public function user_can_update_icon_metadata_and_replace_body(): void
    {
        $icon = Icon::factory()->create(['key' => 'old-key', 'name' => 'Old', 'width' => 24, 'height' => 24]);

        $response = $this->patchJson("/mgmt/v1/spaces/{$this->space->id}/icons/{$icon->id}", [
            'key' => 'new-key',
            'name' => 'New Name',
            'description' => 'Updated',
            'tags' => ['ui'],
            'body' => '<svg viewBox="0 0 48 48"><circle cx="24" cy="24" r="20"/></svg>',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.key', 'new-key');
        $response->assertJsonPath('data.width', 48);

        $icon->refresh();
        $this->assertSame('New Name', $icon->name);
        $this->assertSame(['ui'], $icon->tags);
        $this->assertStringContainsString('<circle', $icon->body);
    }

    #[Test]
    public function user_can_delete_an_icon(): void
    {
        $icon = Icon::factory()->create(['key' => 'trash']);

        $this->deleteJson("/mgmt/v1/spaces/{$this->space->id}/icons/{$icon->id}")
            ->assertNoContent();

        $this->assertSoftDeleted('icons', ['id' => $icon->id]);
    }
}
