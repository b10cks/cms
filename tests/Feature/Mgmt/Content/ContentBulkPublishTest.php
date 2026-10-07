<?php

namespace Tests\Feature\Mgmt\Content;

use App\Models\Management\Space;
use App\Models\Space\Block;
use App\Models\Space\Content;
use App\Models\Space\ContentVersion;
use App\Models\Space\Release;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\SpaceTestingTrait;

class ContentBulkPublishTest extends TestCase
{
    use LazilyRefreshDatabase;
    use SpaceTestingTrait;

    protected User $owner;

    protected Space $space;

    protected Block $pageBlock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->space = Space::factory()->withLive()->create();
        $this->assignSpaceRole($this->space, $this->owner, 'owner');
        $this->setUpSpaceTesting($this->space);
        app()->instance('currentSpace', $this->space);

        $this->pageBlock = Block::query()->create([
            'external_id' => (string) Str::uuid(),
            'name' => 'Page',
            'slug' => 'page',
            'type' => 'root',
            'schema' => [
                'summary' => ['type' => 'text', 'name' => 'Summary', 'required' => true],
            ],
        ]);
    }

    #[Test]
    public function bulk_publish_publishes_each_entry_and_reports_the_ones_that_fail(): void
    {
        $this->actingAs($this->owner);

        $draft = $this->createEntry(draft: ['summary' => 'New']);
        $pending = $this->createEntry(published: ['summary' => 'Live'], draft: ['summary' => 'Edited']);
        $invalid = $this->createEntry(draft: ['summary' => '']);

        $response = $this->postJson(route('mgmt.contents.bulk-publish', ['space' => $this->space->id]), [
            'ids' => [$draft->id, $pending->id, $invalid->id, 'missing'],
            'message' => 'Batch',
        ])->assertOk();

        $this->assertSame([$draft->id, $pending->id], $response->json('data.succeeded'));
        $this->assertSame([$invalid->id, 'missing'], array_column($response->json('data.failed'), 'id'));

        $pending->refresh()->load('published_version');
        $this->assertSame(['summary' => 'Edited'], $pending->published_version->content);
        $this->assertSame('Batch', $pending->published_version->message);
        $this->assertNotNull($draft->refresh()->published_at);
        $this->assertNull($invalid->refresh()->published_at);
    }

    #[Test]
    public function bulk_unpublish_takes_every_entry_offline(): void
    {
        $this->actingAs($this->owner);

        $first = $this->createEntry(published: ['summary' => 'One']);
        $second = $this->createEntry(published: ['summary' => 'Two']);

        $this->postJson(route('mgmt.contents.bulk-unpublish', ['space' => $this->space->id]), [
            'ids' => [$first->id, $second->id],
        ])->assertOk()->assertJsonPath('data.failed', []);

        $this->assertNull($first->refresh()->published_at);
        $this->assertNull($second->refresh()->published_at);
    }

    #[Test]
    public function bulk_publish_requires_the_publish_ability(): void
    {
        $viewer = User::factory()->create();
        $this->assignSpaceRole($this->space, $viewer, 'viewer');
        $entry = $this->createEntry(draft: ['summary' => 'New']);

        $this->actingAs($viewer)
            ->postJson(route('mgmt.contents.bulk-publish', ['space' => $this->space->id]), ['ids' => [$entry->id]])
            ->assertForbidden();

        $this->assertNull($entry->refresh()->published_at);
    }

    #[Test]
    public function release_assignment_by_entry_takes_only_current_drafts(): void
    {
        $this->actingAs($this->owner);

        $release = Release::query()->create(['name' => 'Launch', 'publish_at' => now()->addDay()]);
        $draft = $this->createEntry(draft: ['summary' => 'New']);
        $pending = $this->createEntry(published: ['summary' => 'Live'], draft: ['summary' => 'Edited']);
        $live = $this->createEntry(published: ['summary' => 'Live']);

        $this->postJson(route('mgmt.releases.versions.assign', ['space' => $this->space->id, 'release' => $release->id]), [
            'content_ids' => [$draft->id, $pending->id, $live->id],
        ])->assertOk()->assertJsonPath('meta.assigned', 2);

        $this->assertSame(
            collect([$draft->current_version_id, $pending->current_version_id])->sort()->values()->all(),
            ContentVersion::query()->where('release_id', $release->id)->orderBy('id')->pluck('id')->all(),
        );
    }

    private function createEntry(?array $published = null, ?array $draft = null): Content
    {
        $content = new Content;
        $content->forceFill([
            'block_id' => $this->pageBlock->id,
            'name' => 'Page',
            'slug' => strtolower(Str::random(8)),
            'full_slug' => '/'.strtolower(Str::random(8)),
            'language_iso' => 'en',
        ]);
        $content->id = strtolower((string) Str::ulid());

        $publishedVersion = $published === null ? null : ContentVersion::query()->forceCreate([
            'content_id' => $content->id,
            'content' => $published,
            'created_by_id' => $this->owner->id,
            'published_at' => Carbon::parse('2026-01-01 10:00:00'),
        ]);

        $currentVersion = $draft === null ? $publishedVersion : ContentVersion::query()->forceCreate([
            'content_id' => $content->id,
            'parent_id' => $publishedVersion?->id,
            'content' => $draft,
            'created_by_id' => $this->owner->id,
        ]);

        $content->current_version_id = $currentVersion->id;
        $content->published_version_id = $publishedVersion?->id;
        $content->published_at = $publishedVersion?->published_at;
        $content->save();

        return $content->fresh();
    }
}
