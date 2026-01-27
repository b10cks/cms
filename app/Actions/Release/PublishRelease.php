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

        $success = false;
        \DB::transaction(function () use ($release, $space, $owner, &$success) {
            $release->update([
                'published_at' => now(),
            ]);

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

                $this->searchService->indexContent($content, $space);
            }

            $space->touch('content_updated_at');
            $success = true;
        });
    }
}
