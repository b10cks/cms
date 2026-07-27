<?php

namespace App\Actions\Release;

use App\Models\Management\Space;
use App\Models\Space\Content;
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

            $now = now();
            $versions = $release->versions()
                ->whereNull('published_at')
                ->get(['id', 'content_id']);

            if ($versions->isEmpty()) {
                return;
            }

            // One set-based UPDATE instead of a model save per version. The
            // per-version saving hooks only recompute asset/link/relation ids
            // from an unchanged payload, so nothing load-bearing is skipped.
            $release->versions()
                ->whereNull('published_at')
                ->update([
                    'published_at' => $now,
                    'published_by_id' => $owner?->id,
                ]);

            $contentsById = Content::query()
                ->whereIn('id', $versions->pluck('content_id')->unique())
                ->get()
                ->keyBy('id');

            foreach ($versions as $version) {
                $content = $contentsById->get($version->content_id);

                if (!$content) {
                    continue;
                }

                // Deliberately kept as per-model saves: the content updated/saved
                // hooks (publish automation triggers, slug bookkeeping, menu
                // invalidation, audit) are load-bearing here.
                $content->setPublishedAt($now);
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
