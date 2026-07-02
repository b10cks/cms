<?php

namespace App\Jobs\Content;

use App\Actions\Content\PublishScheduledContent;
use App\Jobs\QueuedJob;
use App\Models\Management\Space;
use App\Models\Space\ContentVersion;
use Illuminate\Support\Facades\Log;

class PublishScheduledContentJob extends QueuedJob
{
    public int $timeout = 300;

    public function __construct(
        protected string $spaceId,
        protected string $contentVersionId,
    ) {
        $this->onConnection('database');
    }

    protected function execute(): void
    {
        $space = Space::find($this->spaceId);
        if (!$space) {
            Log::warning('Space not found for scheduled content job', [
                'space_id' => $this->spaceId,
            ]);
            return;
        }

        app()->offsetSet('currentSpace', $space);

        $version = ContentVersion::find($this->contentVersionId);
        if (!$version) {
            Log::warning('Content version not found for scheduled content job', [
                'content_version_id' => $this->contentVersionId,
                'space_id' => $this->spaceId,
            ]);
            return;
        }

        if ($version->published_at !== null) {
            Log::info('Content version already published, skipping', [
                'content_version_id' => $this->contentVersionId,
                'space_id' => $this->spaceId,
            ]);
            return;
        }

        if ($version->scheduled_at === null || $version->scheduled_at->isFuture()) {
            Log::info('Content version schedule time not yet met or invalid, requeueing', [
                'content_version_id' => $this->contentVersionId,
                'space_id' => $this->spaceId,
                'scheduled_at' => $version->scheduled_at,
            ]);

            if ($version->scheduled_at?->isFuture()) {
                PublishScheduledContentJob::dispatch(
                    $this->spaceId,
                    $this->contentVersionId,
                )->delay($version->scheduled_at);
            }

            return;
        }

        $content = $version->contentModel;
        if (!$content) {
            Log::warning('Content model not found for version', [
                'content_version_id' => $this->contentVersionId,
                'space_id' => $this->spaceId,
            ]);
            return;
        }

        $publishAction = app(PublishScheduledContent::class);
        $publishAction->execute($version, $content, $space, null);

        Log::info('Published scheduled content version', [
            'content_version_id' => $this->contentVersionId,
            'content_id' => $content->id,
            'space_id' => $this->spaceId,
        ]);
    }

    protected function handleFailure(\Throwable $e): void
    {
        Log::error('Failed to publish scheduled content version', [
            'content_version_id' => $this->contentVersionId,
            'space_id' => $this->spaceId,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
    }

    public function tags(): array
    {
        return [
            'content-publishing',
            'space:' . $this->spaceId,
            'content-version:' . $this->contentVersionId,
        ];
    }
}
