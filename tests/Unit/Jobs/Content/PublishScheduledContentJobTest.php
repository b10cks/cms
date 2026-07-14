<?php

namespace Tests\Unit\Jobs\Content;

use App\Jobs\Content\PublishScheduledContentJob;
use App\Models\Management\Space;
use App\Models\Space\Content;
use App\Models\Space\ContentVersion;
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
    public function itPublishesScheduledContent()
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
    public function itSkipsIfAlreadyPublished()
    {
        Log::spy();

        $space = Space::factory()->create();
        $this->setUpSpaceTesting($space);

        $content = Content::factory()->create();
        $version = ContentVersion::factory()
            ->create([
                'content_id' => $content->id,
                'scheduled_at' => now()->subMinute(),
                'published_at' => now(),
            ]);

        $job = new PublishScheduledContentJob($space->id, $version->id);
        $job->handle();

        Log::shouldHaveReceived('info')
            ->withArgs(function ($message) {
                return str_contains($message, 'already published');
            })
            ->once();

        $version->refresh();
        // published_at should not change
        $this->assertNotNull($version->published_at);
    }

    #[Test]
    public function itSkipsIfSpaceNotFound()
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
    public function itSkipsIfVersionNotFound()
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
    public function itSkipsIfContentModelNotFound()
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
    public function itRequeuesIfScheduleTimeNotYetMet()
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
    public function itIncludesTags()
    {
        $spaceId = 'space-123';
        $versionId = 'version-456';

        $job = new PublishScheduledContentJob($spaceId, $versionId);

        $tags = $job->tags();
        $this->assertContains('content-publishing', $tags);
        $this->assertContains('space:' . $spaceId, $tags);
        $this->assertContains('content-version:' . $versionId, $tags);
    }

    #[Test]
    public function itHasCorrectTimeout()
    {
        $job = new PublishScheduledContentJob('space-id', 'version-id');
        $this->assertEquals(300, $job->timeout);
    }
}
