<?php

namespace App\Actions\Release;

use App\Models\Management\Space;
use App\Models\Space\Content;
use App\Models\Space\ContentVersion;
use App\Models\User;
use App\Services\Search\SearchService;
use Illuminate\Contracts\Auth\Authenticatable;

class PublishReleaseContentVersion
{
    public function __construct(
        protected SearchService $searchService
    ) {
    }

    public function execute(ContentVersion $version, Content $content, Space $space, Authenticatable|User|null $owner): void
    {
        if ($version->published_at !== null) {
            return;
        }

        $success = false;
        \DB::transaction(function () use ($version, $content, $space, $owner, &$success) {
            $version->update([
                'published_at' => now(),
                'published_by_id' => $owner?->id,
            ]);

            $content->setPublishedAt(now());
            $content->published_version_id = $version->id;
            $content->save();

            $space->touch('content_updated_at');
            $success = true;
        });

        if ($success) {
            $this->searchService->indexContent($content, $space);
        }
    }
}
