<?php

namespace App\Jobs\Release;

use App\Actions\Release\PublishRelease;
use App\Jobs\QueuedJob;
use App\Models\Management\Space;
use App\Models\Space\Release;
use Illuminate\Support\Facades\Log;

class PublishScheduledReleaseJob extends QueuedJob
{
    public int $timeout = 300;

    public function __construct(
        protected string $spaceId,
        protected string $releaseId,
    ) {
        $this->onConnection('database');
    }

    protected function execute(): void
    {
        $space = Space::find($this->spaceId);
        if (!$space) {
            Log::warning('Space not found for scheduled release job', [
                'space_id' => $this->spaceId,
            ]);
            return;
        }

        app()->offsetSet('currentSpace', $space);

        $release = Release::find($this->releaseId);
        if (!$release) {
            Log::warning('Release not found for scheduled release job', [
                'release_id' => $this->releaseId,
                'space_id' => $this->spaceId,
            ]);
            return;
        }

        if ($release->published_at !== null) {
            Log::info('Release already published, skipping', [
                'release_id' => $this->releaseId,
                'space_id' => $this->spaceId,
            ]);
            return;
        }

        if ($release->committed_at === null) {
            Log::info('Release not yet committed, skipping', [
                'release_id' => $this->releaseId,
                'space_id' => $this->spaceId,
            ]);
            return;
        }

        if ($release->publish_at === null || $release->publish_at->isFuture()) {
            Log::info('Release publish time not yet met or invalid, requeueing', [
                'release_id' => $this->releaseId,
                'space_id' => $this->spaceId,
                'publish_at' => $release->publish_at,
            ]);

            if ($release->publish_at?->isFuture()) {
                PublishScheduledReleaseJob::dispatch(
                    $this->spaceId,
                    $this->releaseId,
                )->delay($release->publish_at);
            }

            return;
        }

        $publishAction = app(PublishRelease::class);
        $publishAction->execute($release, $space, null);

        Log::info('Published scheduled release', [
            'release_id' => $this->releaseId,
            'space_id' => $this->spaceId,
        ]);
    }

    protected function handleFailure(\Throwable $e): void
    {
        Log::error('Failed to publish scheduled release', [
            'release_id' => $this->releaseId,
            'space_id' => $this->spaceId,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
    }

    public function tags(): array
    {
        return [
            'release-publishing',
            'space:' . $this->spaceId,
            'release:' . $this->releaseId,
        ];
    }
}
