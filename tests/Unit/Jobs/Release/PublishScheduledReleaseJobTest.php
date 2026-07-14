<?php

namespace Tests\Unit\Jobs\Release;

use App\Jobs\Release\PublishScheduledReleaseJob;
use App\Models\Management\Space;
use App\Models\Space\Content;
use App\Models\Space\ContentVersion;
use App\Models\Space\Release;
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
    public function itPublishesScheduledRelease()
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
    public function itSkipsIfAlreadyPublished()
    {
        Log::spy();

        $space = Space::factory()->create();
        $this->setUpSpaceTesting($space);

        $release = Release::factory()
            ->create([
                'publish_at' => now()->subMinute(),
                'published_at' => now(),
                'committed_at' => now()->subHour(),
            ]);

        $job = new PublishScheduledReleaseJob($space->id, $release->id);
        $job->handle();

        Log::shouldHaveReceived('info')
            ->withArgs(function ($message) {
                return str_contains($message, 'already published');
            })
            ->once();

        $release->refresh();
        $this->assertNotNull($release->published_at);
    }

    #[Test]
    public function itSkipsIfNotCommitted()
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
    public function itSkipsIfSpaceNotFound()
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
    public function itSkipsIfReleaseNotFound()
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
    public function itRequeuesIfPublishTimeNotYetMet()
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
    public function itIncludesTags()
    {
        $spaceId = 'space-123';
        $releaseId = 'release-456';

        $job = new PublishScheduledReleaseJob($spaceId, $releaseId);

        $tags = $job->tags();
        $this->assertContains('release-publishing', $tags);
        $this->assertContains('space:' . $spaceId, $tags);
        $this->assertContains('release:' . $releaseId, $tags);
    }

    #[Test]
    public function itHasCorrectTimeout()
    {
        $job = new PublishScheduledReleaseJob('space-id', 'release-id');
        $this->assertEquals(300, $job->timeout);
    }
}
