<?php

namespace App\Actions\Release;

use App\Models\Management\Space;
use App\Models\Space\Release;
use App\Models\User;
use App\Services\Search\SearchService;
use Illuminate\Contracts\Auth\Authenticatable;

class PublishRelease
{
    public function __construct(
        protected SearchService $searchService
    ) {
    }

    public function execute(Release $release, Space $space, Authenticatable|User|null $owner): void
    {
        if ($release->published_at !== null || $release->committed_at === null) {
            return;
        }

        $contents = [];
        \DB::transaction(function () use ($release, $space, $owner, &$contents) {
            // Assigned directly, as ReleaseCommitController does for committed_at:
            // published_at is cast but deliberately not fillable, so update() drops
            // it outright once strict mode is off in production.
            $release->published_at = now();
            $release->save();

            $versions = $release->versions()
                ->with('contentModel')
                ->whereNull('published_at')
                ->get();

            foreach ($versions as $version) {
                $content = $version->contentModel;

                if (!$content) {
                    continue;
                }

                $version->update([
                    'published_at' => now(),
                    'published_by_id' => $owner?->id,
                ]);

                $content->setPublishedAt(now());
                $content->published_version_id = $version->id;
                $content->save();
                $contents[] = $content;
            }
        });

        if (\count($contents)) {
            $space->touch('content_updated_at');
            foreach ($contents as $content) {
                $this->searchService->indexContent($content, $space);
            }
        }
    }
}
