<?php

namespace Tests\Feature\Mgmt;

use App\Http\Controllers\Mgmt\IconDataImportController;
use App\Models\Management\Space;
use App\Models\Space\Icon;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\SpaceTestingTrait;

#[CoversClass(IconDataImportController::class)]
class IconImportTest extends TestCase
{
    use RefreshDatabase;
    use SpaceTestingTrait;

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

    private function iconSetFile(array $overrides = []): UploadedFile
    {
        $set = array_merge([
            'prefix' => 'demo',
            'width' => 24,
            'height' => 24,
            'icons' => [
                'activity' => ['body' => '<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>'],
                'airplay' => ['body' => '<path d="m12 15 5 6H7z"/>'],
            ],
            'categories' => [
                'General' => ['activity'],
            ],
        ], $overrides);

        return UploadedFile::fake()->createWithContent('demo.json', json_encode($set));
    }

    #[Test]
    public function it_imports_an_iconify_set_in_addition_mode(): void
    {
        $response = $this->post(
            "/mgmt/v1/spaces/{$this->space->id}/icons/import",
            ['file' => $this->iconSetFile()],
            ['Accept' => 'application/json'],
        );

        $response->assertOk();
        $response->assertJsonPath('summary.total_success', 2);

        $this->assertDatabaseHas('icons', ['key' => 'activity']);
        $this->assertDatabaseHas('icons', ['key' => 'airplay']);

        // Category becomes a tag; body is sanitized (no raw <svg> wrapper stored).
        $activity = Icon::query()->where('key', 'activity')->firstOrFail();
        $this->assertContains('General', $activity->tags ?? []);
        $this->assertStringNotContainsString('<svg', $activity->body);
    }

    #[Test]
    public function addition_mode_overwrites_an_existing_icon_and_reports_the_change(): void
    {
        Icon::factory()->create(['key' => 'activity', 'body' => '<path d="M0 0"/>', 'width' => 24, 'height' => 24]);

        $response = $this->post(
            "/mgmt/v1/spaces/{$this->space->id}/icons/import",
            ['file' => $this->iconSetFile()],
            ['Accept' => 'application/json'],
        );

        $response->assertOk();
        // activity changed, airplay is new.
        $response->assertJsonPath('summary.total_changes', 1);
        $response->assertJsonPath('changes.0.key', 'activity');

        $this->assertSame(1, Icon::query()->where('key', 'activity')->count());
    }

    #[Test]
    public function replacement_mode_prunes_existing_icons_first(): void
    {
        Icon::factory()->create(['key' => 'legacy-icon']);

        $response = $this->post(
            "/mgmt/v1/spaces/{$this->space->id}/icons/import",
            ['file' => $this->iconSetFile(), 'import_mode' => 'replacement'],
            ['Accept' => 'application/json'],
        );

        $response->assertOk();
        $response->assertJsonPath('summary.total_deleted', 1);
        $response->assertJsonPath('summary.total_success', 2);

        $this->assertSoftDeleted('icons', ['key' => 'legacy-icon']);
        $this->assertDatabaseHas('icons', ['key' => 'activity', 'deleted_at' => null]);
    }

    #[Test]
    public function it_rejects_a_file_that_is_not_an_icon_set(): void
    {
        $file = UploadedFile::fake()->createWithContent('bad.json', json_encode(['foo' => 'bar']));

        $response = $this->post(
            "/mgmt/v1/spaces/{$this->space->id}/icons/import",
            ['file' => $file],
            ['Accept' => 'application/json'],
        );

        $response->assertStatus(422);
    }

    #[Test]
    public function a_member_without_manage_ability_is_forbidden(): void
    {
        $viewer = User::factory()->create();
        $this->assignSpaceRole($this->space, $viewer, 'viewer');
        Sanctum::actingAs($viewer);

        $this->post(
            "/mgmt/v1/spaces/{$this->space->id}/icons/import",
            ['file' => $this->iconSetFile()],
            ['Accept' => 'application/json'],
        )->assertForbidden();
    }
}
