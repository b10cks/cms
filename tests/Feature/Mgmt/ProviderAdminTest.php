<?php

namespace Tests\Feature\Mgmt;

use App\Http\Controllers\Mgmt\Provider\ProviderNoteController;
use App\Http\Controllers\Mgmt\Provider\ProviderStatsController;
use App\Models\Management\ProviderNote;
use App\Models\Management\Space;
use App\Models\Management\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(ProviderStatsController::class)]
#[CoversClass(ProviderNoteController::class)]
class ProviderAdminTest extends TestCase
{
    use LazilyRefreshDatabase;

    #[Test]
    public function root_user_can_view_provider_stats(): void
    {
        $rootUser = User::factory()->create(['is_root' => true]);

        Team::factory()->count(2)->create();
        Space::factory()->count(3)->create();
        User::factory()->count(4)->create();

        $this->actingAs($rootUser);

        $response = $this->getJson(route('mgmt.provider.stats'));

        $response->assertOk();
        $response->assertJsonPath('data.summary.teams.total', 2);
        $response->assertJsonPath('data.summary.spaces.total', 3);
        $response->assertJsonPath('data.summary.users.total', 5);
    }

    #[Test]
    public function non_root_user_cannot_view_provider_stats(): void
    {
        $this->createAndActAs();

        $response = $this->getJson(route('mgmt.provider.stats'));

        $response->assertForbidden();
    }

    #[Test]
    public function non_root_user_can_view_provider_notes_index(): void
    {
        ProviderNote::query()->create([
            'title' => 'Status page',
            'url' => 'https://example.com/status',
        ]);

        $this->createAndActAs();

        $response = $this->getJson(route('mgmt.notes.index'));

        $response->assertOk();
        $response->assertJsonPath('data.0.title', 'Status page');
    }

    #[Test]
    public function root_user_can_manage_provider_notes(): void
    {
        $rootUser = User::factory()->create(['is_root' => true]);
        $this->actingAs($rootUser);

        $createResponse = $this->postJson(route('mgmt.notes.store'), [
            'title' => 'Deployment checklist',
            'icon' => 'lucide:rocket',
            'url' => 'https://example.com/checklist',
            'color' => '#F97316',
            'content' => 'Validate backups before rollout.',
            'is_pinned' => true,
        ]);

        $createResponse->assertCreated();
        $noteId = $createResponse->json('data.id');

        $this->assertDatabaseHas('provider_notes', [
            'id' => $noteId,
            'title' => 'Deployment checklist',
            'is_pinned' => true,
        ]);

        $updateResponse = $this->patchJson(route('mgmt.notes.update', $noteId), [
            'title' => 'Updated deployment checklist',
            'content' => 'Validate backups and smoke test the API.',
            'is_pinned' => false,
        ]);

        $updateResponse->assertOk();
        $updateResponse->assertJsonPath('data.title', 'Updated deployment checklist');
        $updateResponse->assertJsonPath('data.is_pinned', false);

        $listResponse = $this->getJson(route('mgmt.notes.index'));

        $listResponse->assertOk();
        $listResponse->assertJsonPath('data.0.id', $noteId);

        $deleteResponse = $this->deleteJson(route('mgmt.notes.destroy', $noteId));

        $deleteResponse->assertNoContent();
        $this->assertDatabaseMissing('provider_notes', [
            'id' => $noteId,
        ]);
    }

    #[Test]
    public function non_root_user_cannot_manage_provider_notes(): void
    {
        $note = ProviderNote::query()->create([
            'title' => 'Private note',
        ]);

        $this->createAndActAs();

        $this->postJson(route('mgmt.notes.store'), [
            'title' => 'Another note',
        ])->assertForbidden();
        $this->patchJson(route('mgmt.notes.update', $note), [
            'title' => 'Updated',
        ])->assertForbidden();
        $this->deleteJson(route('mgmt.notes.destroy', $note))->assertForbidden();
    }
}
