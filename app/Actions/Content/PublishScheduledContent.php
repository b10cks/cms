<?php

namespace App\Actions\Content;

use App\Models\Management\Space;
use App\Models\Space\Content;
use App\Models\Space\ContentVersion;
use App\Models\User;
use App\Services\Search\SearchService;
use Illuminate\Contracts\Auth\Authenticatable;

class PublishScheduledContent
{
    public function __construct(
        protected SearchService $searchService
    ) {
    }

    public function execute(ContentVersion $version, Content $content, Space $space, Authenticatable|User|null $owner): void
    {
        \DB::transaction(function () use ($version, $content, $space, $owner) {
            $version->update([
                'published_at' => now()
            ]);

            $content->published_at = now();
            $content->published_version_id = $version->id;

            $content->save();
            $space->touch('content_updated_at');
        });

        $this->searchService->indexContent($content, $space);
    }
}
