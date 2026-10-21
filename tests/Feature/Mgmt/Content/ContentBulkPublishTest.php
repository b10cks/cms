<?php

namespace Tests\Feature\Mgmt\Content;

use App\Events\Space\ContentsChanged;
use App\Events\Space\ContentUpdated;
use App\Jobs\Content\BulkPublishContentJob;
use App\Models\Management\Space;
use App\Models\Space\Block;
use App\Models\Space\Content;
use App\Models\Space\ContentVersion;
use App\Models\Space\Release;
use App\Models\User;
use App\Services\Content\ContentBulkPublishRun;
use App\Services\Search\SearchService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
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
    public function bulk_publish_returns_a_run_at_once_and_queues_the_job(): void
    {
        Queue::fake();
        $this->actingAs($this->owner);
        $entry = $this->createEntry(draft: ['summary' => 'New']);

        $response = $this->postJson(route('mgmt.contents.bulk-publish', ['space' => $this->space->id]), [
            'ids' => [$entry->id],
        ])->assertAccepted()->assertJsonPath('data.state', 'queued')->assertJsonPath('data.total', 1);

        $runId = $response->json('data.id');
        Queue::assertPushedOn('heavy', BulkPublishContentJob::class, fn (BulkPublishContentJob $job) => $job->runId === $runId);
        $this->assertNull($entry->refresh()->published_at);

        $this->getJson(route('mgmt.contents.bulk-publish.show', ['space' => $this->space->id, 'run' => $runId]))
            ->assertOk()
            ->assertJsonPath('data.id', $runId);
    }

    #[Test]
    public function the_run_publishes_each_entry_reports_failures_and_broadcasts_once(): void
    {
        $this->actingAs($this->owner);

        $draft = $this->createEntry(draft: ['summary' => 'New']);
        $pending = $this->createEntry(published: ['summary' => 'Live'], draft: ['summary' => 'Edited']);
        $invalid = $this->createEntry(draft: ['summary' => '']);
        Event::fake([ContentsChanged::class, ContentUpdated::class]);

        $response = $this->postJson(route('mgmt.contents.bulk-publish', ['space' => $this->space->id]), [
            'ids' => [$draft->id, $pending->id, $invalid->id, 'missing'],
            'message' => 'Batch',
        ])->assertAccepted()->assertJsonPath('data.state', 'completed');

        $this->assertSame([$draft->id, $pending->id], $response->json('data.succeeded'));
        $this->assertSame([$invalid->id, 'missing'], array_column($response->json('data.failed'), 'id'));

        $pending->refresh()->load('published_version');
        $this->assertSame(['summary' => 'Edited'], $pending->published_version->content);
        $this->assertSame('Batch', $pending->published_version->message);
        $this->assertNotNull($draft->refresh()->published_at);
        $this->assertNull($invalid->refresh()->published_at);

        Event::assertNotDispatched(ContentUpdated::class);
        Event::assertDispatchedTimes(ContentsChanged::class, 1);
    }

    #[Test]
    public function an_indexing_failure_is_reported_apart_from_the_publish(): void
    {
        $this->actingAs($this->owner);
        $entry = $this->createEntry(draft: ['summary' => 'New']);

        $search = \Mockery::mock(SearchService::class);
        $search->shouldReceive('syncContents')
            ->once()
            ->withArgs(fn (Collection $contents) => $contents->pluck('id')->all() === [$entry->id])
            ->andReturn([$entry->id => 'OpenSearch is down']);
        app()->instance(SearchService::class, $search);

        $this->postJson(route('mgmt.contents.bulk-publish', ['space' => $this->space->id]), ['ids' => [$entry->id]])
            ->assertAccepted()
            ->assertJsonPath('data.succeeded', [$entry->id])
            ->assertJsonPath('data.failed', [])
            ->assertJsonPath('data.index_failed.0.message', 'OpenSearch is down');

        $this->assertNotNull($entry->refresh()->published_at);
    }

    #[Test]
    public function a_second_run_is_rejected_while_one_is_active(): void
    {
        Queue::fake();
        $this->actingAs($this->owner);
        $entry = $this->createEntry(draft: ['summary' => 'New']);
        $active = ContentBulkPublishRun::lock($this->space);
        $this->assertTrue($active->get());
        ContentBulkPublishRun::markActive($this->space->id, 'run-1');

        $this->postJson(route('mgmt.contents.bulk-unpublish', ['space' => $this->space->id]), ['ids' => [$entry->id]])
            ->assertStatus(409)
            ->assertJsonPath('data.run', 'run-1');

        Queue::assertNotPushed(BulkPublishContentJob::class);
        $active->release();
    }

    #[Test]
    public function the_job_takes_its_own_lease_when_the_queued_lock_expired(): void
    {
        $entry = $this->createEntry(draft: ['summary' => 'New']);
        $run = ContentBulkPublishRun::start($this->space, 'publish', 1);

        (new BulkPublishContentJob($this->space, $this->owner, $run->id(), 'expired-owner', 'publish', [$entry->id]))->handle();

        $this->assertSame('completed', ContentBulkPublishRun::find($this->space->id, $run->id())->toArray()['state']);
        $this->assertNotNull($entry->refresh()->published_at);
        $this->assertNull(ContentBulkPublishRun::activeRunId($this->space->id));
        $this->assertTrue(ContentBulkPublishRun::lock($this->space)->get());
    }

    #[Test]
    public function a_job_whose_lock_another_run_took_fails_without_publishing(): void
    {
        $entry = $this->createEntry(draft: ['summary' => 'New']);
        $run = ContentBulkPublishRun::start($this->space, 'publish', 1);
        $this->assertTrue(ContentBulkPublishRun::lock($this->space)->get());
        ContentBulkPublishRun::markActive($this->space->id, 'other-run');

        (new BulkPublishContentJob($this->space, $this->owner, $run->id(), 'expired-owner', 'publish', [$entry->id]))->handle();

        $state = ContentBulkPublishRun::find($this->space->id, $run->id())->toArray();
        $this->assertSame('failed', $state['state']);
        $this->assertStringStartsWith('Superseded', $state['error']);
        $this->assertNull($entry->refresh()->published_at);
        $this->assertSame('other-run', ContentBulkPublishRun::activeRunId($this->space->id));
        $this->assertFalse(ContentBulkPublishRun::lock($this->space)->get());
    }

    #[Test]
    public function releasing_a_run_leaves_another_active_run_alone(): void
    {
        ContentBulkPublishRun::markActive($this->space->id, 'run-b');

        ContentBulkPublishRun::releaseLock($this->space->id, 'run-a', 'run-a');

        $this->assertSame('run-b', ContentBulkPublishRun::activeRunId($this->space->id));
    }

    #[Test]
    public function a_failed_run_still_broadcasts_the_summary(): void
    {
        Event::fake([ContentsChanged::class]);
        $run = ContentBulkPublishRun::start($this->space, 'publish', 1);

        (new BulkPublishContentJob($this->space, $this->owner, $run->id(), 'owner', 'publish', ['id']))->failed(new \RuntimeException('Timed out'));

        $this->assertSame('failed', ContentBulkPublishRun::find($this->space->id, $run->id())->toArray()['state']);
        Event::assertDispatchedTimes(ContentsChanged::class, 1);
    }

    #[Test]
    public function bulk_unpublish_takes_every_entry_offline(): void
    {
        $this->actingAs($this->owner);

        $first = $this->createEntry(published: ['summary' => 'One']);
        $second = $this->createEntry(published: ['summary' => 'Two']);

        $this->postJson(route('mgmt.contents.bulk-unpublish', ['space' => $this->space->id]), [
            'ids' => [$first->id, $second->id],
        ])->assertAccepted()->assertJsonPath('data.failed', []);

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
