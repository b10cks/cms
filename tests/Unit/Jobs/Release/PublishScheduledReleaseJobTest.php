<?php

namespace Tests\Unit\Jobs\Release;

use App\Jobs\Release\PublishScheduledReleaseJob;
use App\Models\Management\Space;
use App\Models\Space\Content;
use App\Models\Space\ContentVersion;
use App\Models\Space\Release;
use App\Services\Search\SearchService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\SpaceTestingTrait;

class PublishScheduledReleaseJobTest extends TestCase
{
    use LazilyRefreshDatabase;
    use SpaceTestingTrait;

    #[Test]
    public function it_publishes_scheduled_release()
    {
        $space = Space::factory()->create();
        $this->setUpSpaceTesting($space);

        $release = Release::factory()
            ->create([
                'publish_at' => now()->subMinute(),
                'published_at' => null,
                'committed_at' => now()->subHour(),
            ]);

        $content = Content::factory()->create();
        $version = ContentVersion::factory()
            ->create([
                'content_id' => $content->id,
                'release_id' => $release->id,
                'published_at' => null,
            ]);

        $job = new PublishScheduledReleaseJob($space->id, $release->id);
        $job->handle();

        $release->refresh();
        $this->assertNotNull($release->published_at);

        $version->refresh();
        $this->assertNotNull($version->published_at);
    }

    #[Test]
    public function it_retries_indexing_if_release_publication_already_committed(): void
    {
        $space = Space::factory()->create();
        $this->setUpSpaceTesting($space);

        $release = Release::factory()
            ->create([
                'publish_at' => now()->subMinute(),
                'published_at' => now(),
                'committed_at' => now()->subHour(),
            ]);

        $content = Content::factory()->create();
        $version = ContentVersion::factory()->create([
            'content_id' => $content->id,
            'release_id' => $release->id,
            'published_at' => now(),
        ]);
        $content->forceFill([
            'published_at' => now(),
            'published_version_id' => $version->id,
        ])->save();

        $search = \Mockery::mock(SearchService::class);
        $search->shouldReceive('indexContent')
            ->once()
            ->withArgs(fn (Content $indexed, Space $indexedSpace): bool => $indexed->is($content) && $indexedSpace->is($space));
        app()->instance(SearchService::class, $search);

        $job = new PublishScheduledReleaseJob($space->id, $release->id);
        $job->handle();

        $release->refresh();
        $this->assertNotNull($release->published_at);
    }

    #[Test]
    public function it_skips_if_not_committed()
    {
        Log::spy();

        $space = Space::factory()->create();
        $this->setUpSpaceTesting($space);

        $release = Release::factory()
            ->create([
                'publish_at' => now()->subMinute(),
                'published_at' => null,
                'committed_at' => null,
            ]);

        $job = new PublishScheduledReleaseJob($space->id, $release->id);
        $job->handle();

        Log::shouldHaveReceived('info')
            ->withArgs(function ($message) {
                return str_contains($message, 'not yet committed');
            })
            ->once();

        $release->refresh();
        $this->assertNull($release->published_at);
    }

    #[Test]
    public function it_skips_if_space_not_found()
    {
        Log::spy();

        $job = new PublishScheduledReleaseJob('invalid-space-id', 'invalid-release-id');
        $job->handle();

        Log::shouldHaveReceived('warning')
            ->withArgs(function ($message) {
                return str_contains($message, 'Space not found');
            })
            ->once();
    }

    #[Test]
    public function it_skips_if_release_not_found()
    {
        Log::spy();

        $space = Space::factory()->create();
        $this->setUpSpaceTesting($space);

        $job = new PublishScheduledReleaseJob($space->id, 'invalid-release-id');
        $job->handle();

        Log::shouldHaveReceived('warning')
            ->withArgs(function ($message) {
                return str_contains($message, 'Release not found');
            })
            ->once();
    }

    #[Test]
    public function it_requeues_if_publish_time_not_yet_met()
    {
        $space = Space::factory()->create();
        $this->setUpSpaceTesting($space);

        $futureTime = now()->addHours(2);
        $release = Release::factory()
            ->create([
                'publish_at' => $futureTime,
                'published_at' => null,
                'committed_at' => now()->subHour(),
            ]);

        $job = new PublishScheduledReleaseJob($space->id, $release->id);
        $job->handle();

        $release->refresh();
        $this->assertNull($release->published_at);
    }

    #[Test]
    public function it_includes_tags()
    {
        $spaceId = 'space-123';
        $releaseId = 'release-456';

        $job = new PublishScheduledReleaseJob($spaceId, $releaseId);

        $tags = $job->tags();
        $this->assertContains('release-publishing', $tags);
        $this->assertContains('space:'.$spaceId, $tags);
        $this->assertContains('release:'.$releaseId, $tags);
    }

    #[Test]
    public function it_has_correct_timeout()
    {
        $job = new PublishScheduledReleaseJob('space-id', 'release-id');
        $this->assertEquals(300, $job->timeout);
    }
}
