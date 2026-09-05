<?php

namespace Tests\Unit\Jobs\Content;

use App\Jobs\Content\PublishScheduledContentJob;
use App\Models\Management\Space;
use App\Models\Space\Content;
use App\Models\Space\ContentVersion;
use App\Services\Search\SearchService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\SpaceTestingTrait;

class PublishScheduledContentJobTest extends TestCase
{
    use LazilyRefreshDatabase;
    use SpaceTestingTrait;

    #[Test]
    public function it_publishes_scheduled_content()
    {
        $space = Space::factory()->create();
        $this->setUpSpaceTesting($space);

        $content = Content::factory()->create();
        $version = ContentVersion::factory()
            ->create([
                'content_id' => $content->id,
                'scheduled_at' => now()->subMinute(),
                'published_at' => null,
            ]);

        $job = new PublishScheduledContentJob($space->id, $version->id);
        $job->handle();

        $version->refresh();
        $this->assertNotNull($version->published_at);
        $this->assertEquals($content->id, $version->content_id);
    }

    #[Test]
    public function it_retries_indexing_if_publication_already_committed(): void
    {
        $space = Space::factory()->create();
        $this->setUpSpaceTesting($space);

        $content = Content::factory()->create();
        $version = ContentVersion::factory()
            ->create([
                'content_id' => $content->id,
                'scheduled_at' => now()->subMinute(),
                'published_at' => now(),
            ]);

        $search = \Mockery::mock(SearchService::class);
        $search->shouldReceive('indexContent')
            ->once()
            ->withArgs(fn (Content $indexed, Space $indexedSpace): bool => $indexed->is($content) && $indexedSpace->is($space));
        app()->instance(SearchService::class, $search);

        $job = new PublishScheduledContentJob($space->id, $version->id);
        $job->handle();

        $version->refresh();
        $this->assertNotNull($version->published_at);
    }

    #[Test]
    public function it_skips_if_space_not_found()
    {
        Log::spy();

        $job = new PublishScheduledContentJob('invalid-space-id', 'invalid-version-id');
        $job->handle();

        Log::shouldHaveReceived('warning')
            ->withArgs(function ($message) {
                return str_contains($message, 'Space not found');
            })
            ->once();
    }

    #[Test]
    public function it_skips_if_version_not_found()
    {
        Log::spy();

        $space = Space::factory()->create();
        $this->setUpSpaceTesting($space);

        $job = new PublishScheduledContentJob($space->id, 'invalid-version-id');
        $job->handle();

        Log::shouldHaveReceived('warning')
            ->withArgs(function ($message) {
                return str_contains($message, 'Content version not found');
            })
            ->once();
    }

    #[Test]
    public function it_skips_if_content_model_not_found()
    {
        Log::spy();

        $space = Space::factory()->create();
        $this->setUpSpaceTesting($space);

        $version = ContentVersion::factory()
            ->create([
                'content_id' => 'invalid-content-id',
                'scheduled_at' => now()->subMinute(),
                'published_at' => null,
            ]);

        $job = new PublishScheduledContentJob($space->id, $version->id);
        $job->handle();

        Log::shouldHaveReceived('warning')
            ->withArgs(function ($message) {
                return str_contains($message, 'Content model not found');
            })
            ->once();
    }

    #[Test]
    public function it_requeues_if_schedule_time_not_yet_met()
    {
        $space = Space::factory()->create();
        $this->setUpSpaceTesting($space);

        $content = Content::factory()->create();
        $futureTime = now()->addHours(2);
        $version = ContentVersion::factory()
            ->create([
                'content_id' => $content->id,
                'scheduled_at' => $futureTime,
                'published_at' => null,
            ]);

        $job = new PublishScheduledContentJob($space->id, $version->id);
        $job->handle();

        // Verify version was not published
        $version->refresh();
        $this->assertNull($version->published_at);
    }

    #[Test]
    public function it_includes_tags()
    {
        $spaceId = 'space-123';
        $versionId = 'version-456';

        $job = new PublishScheduledContentJob($spaceId, $versionId);

        $tags = $job->tags();
        $this->assertContains('content-publishing', $tags);
        $this->assertContains('space:'.$spaceId, $tags);
        $this->assertContains('content-version:'.$versionId, $tags);
    }

    #[Test]
    public function it_has_correct_timeout()
    {
        $job = new PublishScheduledContentJob('space-id', 'version-id');
        $this->assertEquals(300, $job->timeout);
    }
}
